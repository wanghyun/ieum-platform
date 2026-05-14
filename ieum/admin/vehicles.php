<?php
$sub_menu = '950180';
require_once './_common.php';
require_once IEUM_PATH . '/lib/maps.php';

$g5['title'] = '아이이음 차량 관리';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$map_settings = ieum_map_get_system_settings();
$message = '';
$error = '';

function ieum_vehicle_type_options()
{
    return array(
        'both' => '등원+하원',
        'pickup' => '등원 전용',
        'dropoff' => '하원 전용',
    );
}

function ieum_stop_type_options()
{
    return array(
        'pickup' => '픽업',
        'dropoff' => '하차',
    );
}

function ieum_vehicle_ensure_stop_location_columns()
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

function ieum_vehicle_map_href($row)
{
    $map_url = isset($row['map_url']) ? trim($row['map_url']) : '';
    if ($map_url !== '') {
        return $map_url;
    }
    $query = isset($row['stop_address']) && trim($row['stop_address']) !== '' ? trim($row['stop_address']) : (isset($row['stop_name']) ? trim($row['stop_name']) : '');
    return $query !== '' ? 'https://map.naver.com/v5/search/' . rawurlencode($query) : '';
}

function ieum_vehicle_normalize_stop_order($academy_id)
{
    $academy_id = (int) $academy_id;
    $groups = array();
    $result = sql_query("
        select stop_id, route_id, stop_type
          from " . IEUM_VEHICLE_STOP_TABLE . "
         where academy_id = '{$academy_id}'
      order by route_id asc, field(stop_type, 'pickup', 'dropoff'), sort_order asc, stop_time asc, stop_name asc, stop_id asc
    ", false);
    while ($row = sql_fetch_array($result)) {
        $key = (int) $row['route_id'] . '|' . $row['stop_type'];
        if (!isset($groups[$key])) {
            $groups[$key] = 10;
        }
        sql_query("
            update " . IEUM_VEHICLE_STOP_TABLE . "
               set sort_order = '{$groups[$key]}',
                   updated_at = '" . G5_TIME_YMDHIS . "'
             where academy_id = '{$academy_id}'
               and stop_id = '" . (int) $row['stop_id'] . "'
        ", false);
        $groups[$key] += 10;
    }
}

function ieum_vehicle_move_stop_order($academy_id, $stop_id, $direction)
{
    $academy_id = (int) $academy_id;
    $stop_id = (int) $stop_id;
    $direction = $direction === 'down' ? 'down' : 'up';

    ieum_vehicle_normalize_stop_order($academy_id);

    $current = sql_fetch("
        select *
          from " . IEUM_VEHICLE_STOP_TABLE . "
         where academy_id = '{$academy_id}'
           and stop_id = '{$stop_id}'
         limit 1
    ", false);
    if (empty($current['stop_id'])) {
        return false;
    }

    $operator = $direction === 'up' ? '<' : '>';
    $order = $direction === 'up' ? 'desc' : 'asc';
    $neighbor = sql_fetch("
        select *
          from " . IEUM_VEHICLE_STOP_TABLE . "
         where academy_id = '{$academy_id}'
           and route_id = '" . (int) $current['route_id'] . "'
           and stop_type = '" . sql_escape_string($current['stop_type']) . "'
           and sort_order {$operator} '" . (int) $current['sort_order'] . "'
      order by sort_order {$order}, stop_time {$order}, stop_id {$order}
         limit 1
    ", false);
    if (empty($neighbor['stop_id'])) {
        return false;
    }

    sql_query("
        update " . IEUM_VEHICLE_STOP_TABLE . "
           set sort_order = '" . (int) $neighbor['sort_order'] . "',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where academy_id = '{$academy_id}'
           and stop_id = '{$stop_id}'
    ", false);
    sql_query("
        update " . IEUM_VEHICLE_STOP_TABLE . "
           set sort_order = '" . (int) $current['sort_order'] . "',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where academy_id = '{$academy_id}'
           and stop_id = '" . (int) $neighbor['stop_id'] . "'
    ", false);

    return true;
}

ieum_vehicle_ensure_stop_location_columns();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';

        if ($action === 'move_stop') {
            $stop_id = isset($_POST['stop_id']) ? (int) $_POST['stop_id'] : 0;
            $direction = isset($_POST['direction']) ? trim($_POST['direction']) : 'up';
            $message = ieum_vehicle_move_stop_order($academy_id, $stop_id, $direction) ? '운행 지점 순서를 변경했습니다.' : '이동할 운행 지점이 없습니다.';
        } elseif ($action === 'normalize_stops') {
            ieum_vehicle_normalize_stop_order($academy_id);
            $message = '운행 지점 순서를 시간표 기준으로 정리했습니다.';
        } elseif ($action === 'save_route') {
            $route_id = isset($_POST['route_id']) ? (int) $_POST['route_id'] : 0;
            $route_type = isset($_POST['route_type']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['route_type'])) : 'both';
            $route_name = isset($_POST['route_name']) ? trim($_POST['route_name']) : '';
            $vehicle_label = isset($_POST['vehicle_label']) ? trim($_POST['vehicle_label']) : '';
            $driver_name = isset($_POST['driver_name']) ? trim($_POST['driver_name']) : '';
            $driver_phone = isset($_POST['driver_phone']) ? preg_replace('/[^0-9+\-]/', '', trim($_POST['driver_phone'])) : '';
            $sort_order = isset($_POST['sort_order']) ? max(0, (int) $_POST['sort_order']) : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if (!isset(ieum_vehicle_type_options()[$route_type])) {
                $route_type = 'both';
            }
            if ($route_name === '') {
                $error = '노선명을 입력하세요.';
            } else {
                $route_type_sql = sql_escape_string($route_type);
                $route_name_sql = sql_escape_string($route_name);
                $vehicle_label_sql = sql_escape_string($vehicle_label);
                $driver_name_sql = sql_escape_string($driver_name);
                $driver_phone_sql = sql_escape_string($driver_phone);

                if ($route_id) {
                    sql_query("
                        update " . IEUM_VEHICLE_ROUTE_TABLE . "
                           set route_type = '{$route_type_sql}',
                               route_name = '{$route_name_sql}',
                               vehicle_label = '{$vehicle_label_sql}',
                               driver_name = '{$driver_name_sql}',
                               driver_phone = '{$driver_phone_sql}',
                               sort_order = '{$sort_order}',
                               is_active = '{$is_active}',
                               updated_at = '" . G5_TIME_YMDHIS . "'
                         where route_id = '{$route_id}'
                           and academy_id = '{$academy_id}'
                    ");
                    $message = '차량 노선을 수정했습니다.';
                } else {
                    sql_query("
                        insert into " . IEUM_VEHICLE_ROUTE_TABLE . "
                            set academy_id = '{$academy_id}',
                                route_type = '{$route_type_sql}',
                                route_name = '{$route_name_sql}',
                                vehicle_label = '{$vehicle_label_sql}',
                                driver_name = '{$driver_name_sql}',
                                driver_phone = '{$driver_phone_sql}',
                                sort_order = '{$sort_order}',
                                is_active = '{$is_active}',
                                created_at = '" . G5_TIME_YMDHIS . "'
                    ");
                    $message = '차량 노선을 등록했습니다.';
                }
            }
        } elseif ($action === 'save_stop') {
            $stop_id = isset($_POST['stop_id']) ? (int) $_POST['stop_id'] : 0;
            $route_id = isset($_POST['route_id']) ? (int) $_POST['route_id'] : 0;
            $stop_type = isset($_POST['stop_type']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['stop_type'])) : 'pickup';
            $stop_name = isset($_POST['stop_name']) ? trim($_POST['stop_name']) : '';
            $stop_address = isset($_POST['stop_address']) ? trim($_POST['stop_address']) : '';
            $map_lat = isset($_POST['map_lat']) && trim($_POST['map_lat']) !== '' ? (float) $_POST['map_lat'] : null;
            $map_lng = isset($_POST['map_lng']) && trim($_POST['map_lng']) !== '' ? (float) $_POST['map_lng'] : null;
            $map_url = isset($_POST['map_url']) ? trim($_POST['map_url']) : '';
            $stop_time = isset($_POST['stop_time']) ? preg_replace('/[^0-9:]/', '', trim($_POST['stop_time'])) : '00:00';
            $sort_order = isset($_POST['sort_order']) ? max(0, (int) $_POST['sort_order']) : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if (!isset(ieum_stop_type_options()[$stop_type])) {
                $stop_type = 'pickup';
            }
            if (!preg_match('/^\d{2}:\d{2}$/', $stop_time)) {
                $stop_time = '00:00';
            }
            if ($stop_name === '') {
                $error = '장소명을 입력하세요.';
            } else {
                $route_id_sql = (int) $route_id;
                $stop_type_sql = sql_escape_string($stop_type);
                $stop_name_sql = sql_escape_string($stop_name);
                $stop_address_sql = sql_escape_string($stop_address);
                $map_lat_sql = $map_lat === null ? 'null' : "'" . sql_escape_string(sprintf('%.7F', $map_lat)) . "'";
                $map_lng_sql = $map_lng === null ? 'null' : "'" . sql_escape_string(sprintf('%.7F', $map_lng)) . "'";
                $map_url_sql = sql_escape_string($map_url);
                $stop_time_sql = sql_escape_string($stop_time);

                if ($stop_id) {
                    sql_query("
                        update " . IEUM_VEHICLE_STOP_TABLE . "
                           set route_id = '{$route_id_sql}',
                               stop_type = '{$stop_type_sql}',
                               stop_name = '{$stop_name_sql}',
                               stop_address = '{$stop_address_sql}',
                               map_lat = {$map_lat_sql},
                               map_lng = {$map_lng_sql},
                               map_url = '{$map_url_sql}',
                               stop_time = '{$stop_time_sql}',
                               sort_order = '{$sort_order}',
                               is_active = '{$is_active}',
                               updated_at = '" . G5_TIME_YMDHIS . "'
                         where stop_id = '{$stop_id}'
                           and academy_id = '{$academy_id}'
                    ");
                    $message = '운행 지점을 수정했습니다.';
                } else {
                    sql_query("
                        insert into " . IEUM_VEHICLE_STOP_TABLE . "
                            set academy_id = '{$academy_id}',
                                route_id = '{$route_id_sql}',
                                stop_type = '{$stop_type_sql}',
                                stop_name = '{$stop_name_sql}',
                                stop_address = '{$stop_address_sql}',
                                map_lat = {$map_lat_sql},
                                map_lng = {$map_lng_sql},
                                map_url = '{$map_url_sql}',
                                stop_time = '{$stop_time_sql}',
                                sort_order = '{$sort_order}',
                                is_active = '{$is_active}',
                                created_at = '" . G5_TIME_YMDHIS . "'
                    ");
                    $message = '운행 지점을 등록했습니다.';
                }
            }
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$routes = sql_query("
    select r.*,
           (select count(*)
              from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
             where sv.academy_id = r.academy_id
               and sv.route_id = r.route_id
               and sv.is_active = 1) as student_count
      from " . IEUM_VEHICLE_ROUTE_TABLE . " r
     where r.academy_id = '{$academy_id}'
  order by r.is_active desc, r.sort_order asc, r.route_name asc
", false);

$route_options = array();
$route_options_result = sql_query("
    select *
      from " . IEUM_VEHICLE_ROUTE_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by sort_order asc, route_name asc
", false);
while ($route = sql_fetch_array($route_options_result)) {
    $route_options[] = $route;
}

$stops = sql_query("
    select s.*, r.route_name, r.vehicle_label
      from " . IEUM_VEHICLE_STOP_TABLE . " s
 left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = s.route_id and r.academy_id = s.academy_id
     where s.academy_id = '{$academy_id}'
  order by s.is_active desc, field(s.stop_type, 'pickup', 'dropoff'), r.sort_order asc, s.sort_order asc, s.stop_time asc, s.stop_name asc
", false);
$stop_rows = array();
while ($stop = sql_fetch_array($stops)) {
    $stop_rows[] = $stop;
}

$route_summary = array();
foreach ($route_options as $route) {
    $route_summary[(int) $route['route_id']] = array(
        'route_name' => $route['route_name'],
        'vehicle_label' => $route['vehicle_label'],
        'pickup' => 0,
        'dropoff' => 0,
    );
}
foreach ($stop_rows as $stop) {
    $rid = (int) $stop['route_id'];
    if (!isset($route_summary[$rid])) {
        $route_summary[$rid] = array(
            'route_name' => $stop['route_name'] ?: '노선 미지정',
            'vehicle_label' => $stop['vehicle_label'],
            'pickup' => 0,
            'dropoff' => 0,
        );
    }
    if (!empty($stop['is_active']) && isset($route_summary[$rid][$stop['stop_type']])) {
        $route_summary[$rid][$stop['stop_type']]++;
    }
}
$map_markers = array();
foreach ($stop_rows as $stop) {
    if (empty($stop['is_active']) || $stop['map_lat'] === null || $stop['map_lng'] === null || $stop['map_lat'] === '' || $stop['map_lng'] === '') {
        continue;
    }
    $map_markers[] = array(
        'stop_id' => (int) $stop['stop_id'],
        'name' => $stop['stop_name'],
        'address' => isset($stop['stop_address']) ? $stop['stop_address'] : '',
        'lat' => (float) $stop['map_lat'],
        'lng' => (float) $stop['map_lng'],
        'time' => $stop['stop_time'],
        'type' => $stop['stop_type'],
        'route' => isset($stop['route_name']) ? $stop['route_name'] : '',
    );
}
$can_use_dynamic_map = !empty($map_settings['use_dynamic_map']) && ieum_map_has_api_key($map_settings);
$can_use_geocoding = !empty($map_settings['use_geocoding']) && ieum_map_has_api_key($map_settings) && ieum_map_has_secret($map_settings);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<?php if ($can_use_dynamic_map) { ?>
<script src="https://oapi.map.naver.com/openapi/v3/maps.js?ncpClientId=<?php echo get_text($map_settings['naver_client_id']); ?>"></script>
<?php } ?>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}
.wrap{max-width:1240px;margin:28px auto;padding:0 20px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px;overflow:hidden}
.vehicle-setup-grid{display:grid;grid-template-columns:minmax(360px,.95fr) minmax(420px,1.25fr);gap:18px;align-items:start}.vehicle-setup-grid .panel{margin-bottom:0}.map-panel{min-height:100%}.map-stage{height:430px;border:1px solid #d9e2f1;border-radius:10px;background:#eef2f7;overflow:hidden;position:relative}.map-stage.empty{display:flex;align-items:center;justify-content:center;padding:22px;text-align:center;color:#667085;font-weight:900;line-height:1.6}.map-stage .map-empty-inner{max-width:420px}.map-tools{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:10px}.map-tools small{color:#667085}.map-results{display:none;margin-top:10px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;padding:10px;color:#344054;font-weight:800}.map-results.show{display:block}.map-results button{margin-top:8px}.map-pin-count{display:inline-flex;align-items:center;border-radius:999px;background:#eef5ff;color:#1769c2;padding:6px 10px;font-size:12px;font-weight:900}
h1{margin:0 0 8px;font-size:26px}.meta{color:#667085;margin-bottom:16px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}
input,select{width:100%;max-width:100%;min-width:0;border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}.grid.route,.grid.stop{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;align-items:center}.grid.route input[name="route_name"],.grid.stop select[name="route_id"],.grid.stop input[name="stop_name"],.grid.stop input[name="stop_address"],.grid.stop input[name="map_url"],.grid.stop .coord-grid,.grid.stop .map-results{grid-column:1/-1}.grid.route label,.grid.stop label{display:flex;align-items:center;gap:6px;min-height:40px;white-space:nowrap}.grid.route label input,.grid.stop label input{width:auto;flex:0 0 auto}.coord-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;min-width:0}.map-link{display:inline-flex;align-items:center;justify-content:center;min-height:32px;border-radius:999px;background:#eef5ff;color:#1769c2;text-decoration:none;font-size:12px;font-weight:900}.map-search{background:#eef5ff;border-color:#bfdbfe;color:#1769c2}.map-search.loading{opacity:.65;pointer-events:none}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.print{background:#111827;border-color:#111827;color:#fff}
table{width:100%;min-width:980px;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center}td input,td select{min-width:110px}td.left input{margin-bottom:6px}th{background:#72829d;color:#fff}.panel>table{display:block;overflow-x:auto;white-space:nowrap}.left{text-align:left}.muted{color:#667085;font-size:12px;line-height:1.45}.inactive{background:#fafafa;color:#8a94a6}.section-title{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:0 0 14px;flex-wrap:wrap}
.flow-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.flow-card{border:1px solid #d9e2f1;border-radius:10px;overflow:hidden;background:#fff}.flow-head{display:flex;justify-content:space-between;gap:10px;padding:12px 14px;background:#15204a;color:#fff;font-weight:900}.flow-head small{color:#cbd5e1}.flow-list{list-style:none;margin:0;padding:0}.flow-list li{display:grid;grid-template-columns:72px 1fr auto;gap:10px;align-items:center;padding:11px 14px;border-top:1px solid #edf1f7}.flow-time{font-weight:900;color:#1769c2}.flow-name{font-weight:900}.flow-meta{display:block;margin-top:3px;color:#667085;font-size:12px}.flow-empty{padding:18px;color:#667085;text-align:center;background:#f8fafc}.flow-badge{display:inline-flex;align-items:center;border-radius:999px;background:#eef5ff;color:#1769c2;padding:4px 8px;font-size:12px;font-weight:900;text-decoration:none}
.api-hint{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;padding:12px 14px;margin-bottom:18px;color:#344054}.api-hint strong{color:#1769c2}.api-hint a{flex:0 0 auto}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-bottom:18px}.summary-card{border:1px solid #d9e2f1;border-radius:10px;background:#fff;padding:14px;min-width:0}.summary-card .label{color:#667085;font-size:13px;font-weight:800}.summary-card strong{display:block;margin-top:6px;font-size:22px;line-height:1.25;word-break:keep-all;overflow-wrap:anywhere}.summary-card small{display:block;margin-top:4px;color:#667085}.order-actions{display:flex;gap:4px;justify-content:center}.order-actions form{display:inline}.mini{min-height:30px;padding:4px 8px;font-size:13px}.section-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.table-wrap{width:100%;overflow-x:auto}
@media(max-width:980px){.vehicle-setup-grid{grid-template-columns:1fr}.grid.route,.grid.stop{grid-template-columns:repeat(auto-fit,minmax(140px,1fr))}.grid.route input[name="route_name"],.grid.stop select[name="route_id"],.grid.stop input[name="stop_name"],.grid.stop input[name="stop_address"],.grid.stop input[name="map_url"],.grid.stop .coord-grid,.grid.stop .map-results{grid-column:1/-1}table{white-space:nowrap}.section-title{align-items:flex-start;flex-direction:column}.map-stage{height:340px}}
@media(max-width:1100px){.panel>table.vehicle-stop-table,.panel>table.vehicle-route-table{display:block;min-width:0;overflow:visible;white-space:normal}.vehicle-stop-table thead,.vehicle-route-table thead{display:none}.vehicle-stop-table tbody,.vehicle-route-table tbody{display:block}.vehicle-stop-table tr,.vehicle-route-table tr{display:block;border:1px solid #d8dee9;border-radius:10px;margin-bottom:12px;padding:10px;background:#fff}.vehicle-stop-table tr.inactive,.vehicle-route-table tr.inactive{background:#fafafa}.vehicle-stop-table td,.vehicle-route-table td{display:grid;grid-template-columns:96px minmax(0,1fr);gap:10px;align-items:center;border:0;border-top:1px solid #edf1f7;text-align:left;padding:10px 0}.vehicle-stop-table td:first-child,.vehicle-route-table td:first-child{border-top:0}.vehicle-stop-table td:before,.vehicle-route-table td:before{content:attr(data-label);font-size:13px;font-weight:900;color:#667085}.vehicle-stop-table td input,.vehicle-stop-table td select,.vehicle-route-table td input,.vehicle-route-table td select{min-width:0}.vehicle-stop-table .order-actions,.vehicle-route-table .order-actions{justify-content:flex-start}}
@media(max-width:760px){.flow-grid,.summary-grid{grid-template-columns:1fr}.flow-list li{grid-template-columns:60px 1fr}.api-hint{align-items:flex-start;flex-direction:column}.api-hint a{width:100%}.grid.route,.grid.stop,.coord-grid{grid-template-columns:1fr}.map-stage{height:300px}}
</style>
</head>
<body>
<?php echo ieum_admin_header('vehicles'); ?>
<?php echo ieum_admin_subnav('vehicles'); ?>
<main class="wrap">
    <h1>차량 관리</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 노선은 차량/기사 묶음, 운행 지점은 장소+시간+지도입니다. 학생은 픽업 지점과 하차 지점을 각각 선택합니다.</div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <div class="api-hint">
        <div>현재 지도 방식: <strong><?php echo get_text(ieum_map_mode_label($map_settings)); ?></strong> · 지도 API 키는 본사 공통 설정을 사용합니다.</div>
        <?php if ($is_admin === 'super') { ?>
        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/map_settings.php">지도 API 설정</a>
        <?php } ?>
    </div>

    <section class="summary-grid">
        <?php foreach ($route_summary as $summary) { ?>
        <article class="summary-card">
            <div class="label"><?php echo get_text($summary['vehicle_label'] ?: '차량 미지정'); ?></div>
            <strong><?php echo get_text($summary['route_name']); ?></strong>
            <small>픽업 <?php echo number_format((int) $summary['pickup']); ?>곳 · 하차 <?php echo number_format((int) $summary['dropoff']); ?>곳</small>
        </article>
        <?php } ?>
        <?php if (empty($route_summary)) { ?>
        <article class="summary-card">
            <div class="label">차량 운영 준비</div>
            <strong>노선 없음</strong>
            <small>1호차, 2호차처럼 노선을 먼저 등록하세요.</small>
        </article>
        <?php } ?>
    </section>

    <section class="vehicle-setup-grid">
    <div>
    <section class="panel">
        <div class="section-title">
            <h2>노선 등록</h2>
            <div>
                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/vehicle_boarding.php">탑승 확인</a>
                <a class="btn print" href="<?php echo IEUM_URL; ?>/admin/vehicle_journal.php" target="_blank" rel="noopener">차량 일지 인쇄</a>
            </div>
        </div>
        <form method="post" class="grid route">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save_route">
            <select name="route_type">
                <?php foreach (ieum_vehicle_type_options() as $value => $label) { ?>
                <option value="<?php echo get_text($value); ?>"><?php echo get_text($label); ?></option>
                <?php } ?>
            </select>
            <input type="text" name="route_name" placeholder="노선명 예: 1호차 A코스" maxlength="80" required>
            <input type="text" name="vehicle_label" placeholder="차량명" maxlength="50">
            <input type="text" name="driver_name" placeholder="기사/사범" maxlength="50">
            <input type="text" name="driver_phone" placeholder="연락처" maxlength="30">
            <input type="number" name="sort_order" placeholder="순서" min="0">
            <label><input type="checkbox" name="is_active" value="1" checked> 사용</label>
            <button type="submit" class="btn primary">추가</button>
        </form>
    </section>

    <section class="panel">
        <div class="section-title">
            <h2>운행 지점 등록</h2>
            <form method="post" class="section-tools">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="normalize_stops">
                <button type="submit" class="btn">시간순 정렬</button>
            </form>
        </div>
        <form method="post" class="grid stop" id="newStopForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save_stop">
            <select name="stop_type">
                <?php foreach (ieum_stop_type_options() as $value => $label) { ?>
                <option value="<?php echo get_text($value); ?>"><?php echo get_text($label); ?></option>
                <?php } ?>
            </select>
            <select name="route_id">
                <option value="0">노선 선택 안함</option>
                <?php foreach ($route_options as $route) { ?>
                <option value="<?php echo (int) $route['route_id']; ?>"><?php echo get_text($route['route_name']); ?></option>
                <?php } ?>
            </select>
            <input type="text" name="stop_name" placeholder="장소 예: 아이이음초등학교" maxlength="100" required>
            <input type="text" name="stop_address" placeholder="주소 또는 기사님 참고 위치" maxlength="160">
            <button type="button" class="btn map-search">주소 검색</button>
            <input type="time" name="stop_time" value="14:10" required>
            <input type="number" name="sort_order" placeholder="순서" min="0">
            <input type="text" name="map_url" placeholder="지도 링크 선택" maxlength="255">
            <div class="coord-grid">
                <input type="text" name="map_lat" placeholder="위도">
                <input type="text" name="map_lng" placeholder="경도">
            </div>
            <div class="map-results" aria-live="polite"></div>
            <label><input type="checkbox" name="is_active" value="1" checked> 사용</label>
            <button type="submit" class="btn primary">추가</button>
        </form>
    </section>
    </div>

    <section class="panel map-panel">
        <div class="section-title">
            <h2>정류장 지도</h2>
            <span class="map-pin-count">좌표 등록 <?php echo number_format(count($map_markers)); ?>곳</span>
        </div>
        <?php if ($can_use_dynamic_map) { ?>
        <div id="vehicleMap" class="map-stage"></div>
        <?php } else { ?>
        <div class="map-stage empty">
            <div class="map-empty-inner">
                지도 API 설정이 완료되면 이 영역에 정류장 위치가 표시됩니다.<br>
                지금은 주소 검색 링크와 수동 좌표 입력으로 운영할 수 있습니다.
            </div>
        </div>
        <?php } ?>
        <div class="map-tools">
            <small>주소 검색 후 선택하면 위도/경도와 지도 링크가 자동 입력됩니다.</small>
            <?php if ($is_admin === 'super') { ?><a class="btn" href="<?php echo IEUM_URL; ?>/admin/map_settings.php">지도 API 설정</a><?php } ?>
        </div>
    </section>
    </section>

    <section class="panel">
        <h2>노선별 운행 동선</h2>
        <div class="flow-grid">
            <?php
            $flow_groups = array();
            foreach ($stop_rows as $row) {
                if (!(int) $row['is_active']) {
                    continue;
                }
                $group_key = $row['stop_type'] . '|' . (int) $row['route_id'];
                if (!isset($flow_groups[$group_key])) {
                    $flow_groups[$group_key] = array(
                        'stop_type' => $row['stop_type'],
                        'route_name' => $row['route_name'] ?: '노선 미지정',
                        'vehicle_label' => $row['vehicle_label'],
                        'items' => array(),
                    );
                }
                $flow_groups[$group_key]['items'][] = $row;
            }
            ?>
            <?php if (empty($flow_groups)) { ?>
            <div class="flow-empty">등록된 운행 동선이 없습니다.</div>
            <?php } ?>
            <?php foreach ($flow_groups as $flow) { ?>
            <article class="flow-card">
                <div class="flow-head">
                    <span><?php echo get_text(($flow['stop_type'] === 'pickup' ? '픽업' : '하차') . ' · ' . $flow['route_name']); ?></span>
                    <small><?php echo get_text($flow['vehicle_label']); ?></small>
                </div>
                <ol class="flow-list">
                    <?php foreach ($flow['items'] as $item) { ?>
                    <?php $map_href = ieum_vehicle_map_href($item); ?>
                    <li>
                        <span class="flow-time"><?php echo get_text($item['stop_time']); ?></span>
                        <span>
                            <span class="flow-name"><?php echo get_text($item['stop_name']); ?></span>
                            <?php if (!empty($item['stop_address'])) { ?><span class="flow-meta"><?php echo get_text($item['stop_address']); ?></span><?php } ?>
                        </span>
                        <?php if ($map_href !== '') { ?><a class="flow-badge" href="<?php echo get_text($map_href); ?>" target="_blank" rel="noopener">지도</a><?php } ?>
                    </li>
                    <?php } ?>
                </ol>
            </article>
            <?php } ?>
        </div>
    </section>

    <section class="panel">
        <h2>운행 지점</h2>
        <table class="vehicle-stop-table">
            <thead><tr><th>구분</th><th>시간</th><th>장소</th><th>주소/지도</th><th>노선</th><th>차량</th><th>순서</th><th>이동</th><th>상태</th><th>수정</th></tr></thead>
            <tbody>
            <?php $i = 0; foreach ($stop_rows as $row) { $i++; ?>
            <tr class="<?php echo $row['is_active'] ? '' : 'inactive'; ?>">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="save_stop">
                    <input type="hidden" name="stop_id" value="<?php echo (int) $row['stop_id']; ?>">
                    <td data-label="구분">
                        <select name="stop_type">
                            <?php foreach (ieum_stop_type_options() as $value => $label) { ?>
                            <option value="<?php echo get_text($value); ?>" <?php echo get_selected($row['stop_type'], $value); ?>><?php echo get_text($label); ?></option>
                            <?php } ?>
                        </select>
                    </td>
                    <td data-label="시간"><input type="time" name="stop_time" value="<?php echo get_text($row['stop_time']); ?>"></td>
                    <td data-label="장소"><input type="text" name="stop_name" value="<?php echo get_text($row['stop_name']); ?>" maxlength="100"></td>
                    <td class="left" data-label="주소/지도">
                        <input type="text" name="stop_address" value="<?php echo get_text(isset($row['stop_address']) ? $row['stop_address'] : ''); ?>" maxlength="160" placeholder="주소 또는 참고 위치">
                        <input type="text" name="map_url" value="<?php echo get_text(isset($row['map_url']) ? $row['map_url'] : ''); ?>" maxlength="255" placeholder="지도 링크">
                        <button type="button" class="btn map-search">지도 검색</button>
                        <div class="coord-grid">
                            <input type="text" name="map_lat" value="<?php echo get_text(isset($row['map_lat']) ? $row['map_lat'] : ''); ?>" placeholder="위도">
                            <input type="text" name="map_lng" value="<?php echo get_text(isset($row['map_lng']) ? $row['map_lng'] : ''); ?>" placeholder="경도">
                        </div>
                        <?php $map_href = ieum_vehicle_map_href($row); if ($map_href !== '') { ?>
                        <a class="map-link" href="<?php echo get_text($map_href); ?>" target="_blank" rel="noopener">지도 확인</a>
                        <?php } ?>
                    </td>
                    <td data-label="노선">
                        <select name="route_id">
                            <option value="0">노선 선택 안함</option>
                            <?php foreach ($route_options as $route) { ?>
                            <option value="<?php echo (int) $route['route_id']; ?>" <?php echo get_selected((int) $row['route_id'], (int) $route['route_id']); ?>><?php echo get_text($route['route_name']); ?></option>
                            <?php } ?>
                        </select>
                    </td>
                    <td data-label="차량"><?php echo get_text($row['vehicle_label']); ?></td>
                    <td data-label="순서"><input type="number" name="sort_order" value="<?php echo (int) $row['sort_order']; ?>" min="0"></td>
                    <td data-label="이동">
                        <div class="order-actions">
                            <button type="submit" class="btn mini" form="move-up-<?php echo (int) $row['stop_id']; ?>">↑</button>
                            <button type="submit" class="btn mini" form="move-down-<?php echo (int) $row['stop_id']; ?>">↓</button>
                        </div>
                    </td>
                    <td data-label="상태"><label><input type="checkbox" name="is_active" value="1" <?php echo $row['is_active'] ? 'checked' : ''; ?>> 사용</label></td>
                    <td data-label="수정">
                        <button type="submit" class="btn">저장</button>
                    </td>
                </form>
                <form method="post" id="move-up-<?php echo (int) $row['stop_id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="move_stop">
                    <input type="hidden" name="stop_id" value="<?php echo (int) $row['stop_id']; ?>">
                    <input type="hidden" name="direction" value="up">
                </form>
                <form method="post" id="move-down-<?php echo (int) $row['stop_id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="move_stop">
                    <input type="hidden" name="stop_id" value="<?php echo (int) $row['stop_id']; ?>">
                    <input type="hidden" name="direction" value="down">
                </form>
            </tr>
            <?php } ?>
            <?php if ($i === 0) { ?><tr><td colspan="10">등록된 운행 지점이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <h2>노선</h2>
        <table class="vehicle-route-table">
            <thead><tr><th>구분</th><th>노선명</th><th>차량</th><th>담당</th><th>연락처</th><th>배정 학생</th><th>상태</th><th>수정</th></tr></thead>
            <tbody>
            <?php $i = 0; while ($row = sql_fetch_array($routes)) { $i++; ?>
                <tr class="<?php echo $row['is_active'] ? '' : 'inactive'; ?>">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="save_route">
                        <input type="hidden" name="route_id" value="<?php echo (int) $row['route_id']; ?>">
                        <input type="hidden" name="sort_order" value="<?php echo (int) $row['sort_order']; ?>">
                        <td data-label="구분">
                            <select name="route_type">
                                <?php foreach (ieum_vehicle_type_options() as $value => $label) { ?>
                                <option value="<?php echo get_text($value); ?>" <?php echo get_selected($row['route_type'], $value); ?>><?php echo get_text($label); ?></option>
                                <?php } ?>
                            </select>
                        </td>
                        <td data-label="노선명"><input type="text" name="route_name" value="<?php echo get_text($row['route_name']); ?>" maxlength="80"></td>
                        <td data-label="차량"><input type="text" name="vehicle_label" value="<?php echo get_text($row['vehicle_label']); ?>" maxlength="50"></td>
                        <td data-label="담당"><input type="text" name="driver_name" value="<?php echo get_text($row['driver_name']); ?>" maxlength="50"></td>
                        <td data-label="연락처"><input type="text" name="driver_phone" value="<?php echo get_text($row['driver_phone']); ?>" maxlength="30"></td>
                        <td data-label="배정 학생"><?php echo number_format((int) $row['student_count']); ?>명</td>
                        <td data-label="상태"><label><input type="checkbox" name="is_active" value="1" <?php echo $row['is_active'] ? 'checked' : ''; ?>> 사용</label></td>
                        <td data-label="수정">
                            <button type="submit" class="btn">저장</button>
                        </td>
                    </form>
                </tr>
            <?php } ?>
            <?php if ($i === 0) { ?><tr><td colspan="8">등록된 차량 노선이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
    </section>
</main>
<script>
const ieumVehicleMapConfig = {
    canUseDynamicMap: <?php echo $can_use_dynamic_map ? 'true' : 'false'; ?>,
    canUseGeocoding: <?php echo $can_use_geocoding ? 'true' : 'false'; ?>,
    geocodeUrl: '<?php echo IEUM_URL; ?>/admin/map_geocode.php',
    markers: <?php echo json_encode($map_markers, JSON_UNESCAPED_UNICODE); ?>
};

(function () {
    let vehicleMap = null;
    let markerObjects = [];

    const buildMapSearchUrl = (value) => {
        const query = (value || '').trim();
        return query ? 'https://map.naver.com/v5/search/' + encodeURIComponent(query) : '';
    };

    const getFormQuery = (form) => {
        const name = form.querySelector('[name="stop_name"]');
        const address = form.querySelector('[name="stop_address"]');
        return ((address && address.value.trim()) || (name && name.value.trim()) || '').trim();
    };

    const setField = (form, name, value) => {
        const input = form.querySelector(`[name="${name}"]`);
        if (input) input.value = value || '';
    };

    const showResult = (form, html) => {
        let box = form.querySelector('.map-results');
        if (!box) {
            box = document.createElement('div');
            box.className = 'map-results';
            form.appendChild(box);
        }
        box.innerHTML = html;
        box.classList.add('show');
    };

    const focusMap = (lat, lng, title) => {
        if (!vehicleMap || !window.naver || !naver.maps || !lat || !lng) return;
        const position = new naver.maps.LatLng(Number(lat), Number(lng));
        vehicleMap.setCenter(position);
        vehicleMap.setZoom(16);
        new naver.maps.Marker({
            position,
            map: vehicleMap,
            title: title || '선택 위치'
        });
    };

    const initMap = () => {
        if (!ieumVehicleMapConfig.canUseDynamicMap || !window.naver || !naver.maps) return;
        const mapEl = document.getElementById('vehicleMap');
        if (!mapEl) return;
        const first = ieumVehicleMapConfig.markers[0] || { lat: 37.5665, lng: 126.9780 };
        vehicleMap = new naver.maps.Map(mapEl, {
            center: new naver.maps.LatLng(Number(first.lat), Number(first.lng)),
            zoom: ieumVehicleMapConfig.markers.length ? 14 : 11,
            zoomControl: true,
            zoomControlOptions: { position: naver.maps.Position.TOP_RIGHT }
        });
        markerObjects = ieumVehicleMapConfig.markers.map((item) => {
            const marker = new naver.maps.Marker({
                position: new naver.maps.LatLng(Number(item.lat), Number(item.lng)),
                map: vehicleMap,
                title: item.name
            });
            const info = new naver.maps.InfoWindow({
                content: `<div style="padding:10px 12px;font-size:13px;line-height:1.45"><strong>${item.name}</strong><br>${item.time || ''} · ${item.type === 'pickup' ? '픽업' : '하차'}<br>${item.address || item.route || ''}</div>`
            });
            naver.maps.Event.addListener(marker, 'click', () => info.open(vehicleMap, marker));
            return marker;
        });
    };

    const searchAddress = async (form, button) => {
        const query = getFormQuery(form);
        if (!query) {
            alert('장소명 또는 주소를 먼저 입력하세요.');
            return;
        }
        const fallbackUrl = buildMapSearchUrl(query);
        const mapInput = form.querySelector('[name="map_url"]');
        if (mapInput && !mapInput.value.trim()) {
            mapInput.value = fallbackUrl;
        }
        if (!ieumVehicleMapConfig.canUseGeocoding) {
            window.open(fallbackUrl, '_blank', 'noopener');
            return;
        }
        button.classList.add('loading');
        button.textContent = '검색 중';
        try {
            const response = await fetch(ieumVehicleMapConfig.geocodeUrl + '?query=' + encodeURIComponent(query), {
                credentials: 'same-origin'
            });
            const data = await response.json();
            if (!data.success) {
                showResult(form, `${data.message || '검색에 실패했습니다.'}<br><button type="button" class="btn mini open-map-link">네이버 지도에서 열기</button>`);
                return;
            }
            setField(form, 'stop_address', data.address || query);
            setField(form, 'map_lat', data.lat || '');
            setField(form, 'map_lng', data.lng || '');
            setField(form, 'map_url', data.mapUrl || fallbackUrl);
            focusMap(data.lat, data.lng, data.address || query);
            showResult(form, `주소 확인: <strong>${data.address || query}</strong><br>좌표가 입력되었습니다.`);
        } catch (error) {
            showResult(form, `주소 검색 중 오류가 발생했습니다.<br><button type="button" class="btn mini open-map-link">네이버 지도에서 열기</button>`);
        } finally {
            button.classList.remove('loading');
            button.textContent = '주소 검색';
        }
    };

    document.addEventListener('click', (event) => {
        const mapLink = event.target.closest('.open-map-link');
        if (mapLink) {
            const form = mapLink.closest('form');
            const query = form ? getFormQuery(form) : '';
            const url = buildMapSearchUrl(query);
            if (url) window.open(url, '_blank', 'noopener');
            return;
        }
        const button = event.target.closest('.map-search');
        if (!button) return;
        const form = button.closest('form');
        if (!form) return;
        searchAddress(form, button);
    });

    initMap();
})();
</script>
</body>
</html>
