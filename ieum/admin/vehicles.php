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
$return_stop_id = 0;

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

function ieum_vehicle_ensure_route_driver_pin_column()
{
    $exists = sql_fetch("show columns from " . IEUM_VEHICLE_ROUTE_TABLE . " like 'driver_pin'", false);
    if (empty($exists['Field'])) {
        sql_query("alter table " . IEUM_VEHICLE_ROUTE_TABLE . " add driver_pin varchar(20) not null default '' after driver_phone", false);
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

function ieum_vehicle_count_sql($sql)
{
    $row = sql_fetch($sql, false);
    return isset($row['cnt']) ? (int) $row['cnt'] : 0;
}

function ieum_vehicle_route_usage_counts($academy_id, $route_id)
{
    $academy_id = (int) $academy_id;
    $route_id = (int) $route_id;
    return array(
        'stops' => ieum_vehicle_count_sql("
            select count(*) as cnt
              from " . IEUM_VEHICLE_STOP_TABLE . "
             where academy_id = '{$academy_id}'
               and route_id = '{$route_id}'
        "),
        'students' => ieum_vehicle_count_sql("
            select count(*) as cnt
              from " . IEUM_STUDENT_VEHICLE_TABLE . "
             where academy_id = '{$academy_id}'
               and route_id = '{$route_id}'
               and is_active = 1
        "),
        'logs' => ieum_vehicle_count_sql("
            select count(*) as cnt
              from " . IEUM_VEHICLE_BOARDING_TABLE . "
             where academy_id = '{$academy_id}'
               and route_id = '{$route_id}'
        "),
        'runs' => ieum_vehicle_count_sql("
            select count(*) as cnt
              from " . IEUM_VEHICLE_RUN_TABLE . "
             where academy_id = '{$academy_id}'
               and route_id = '{$route_id}'
        "),
    );
}

function ieum_vehicle_stop_usage_counts($academy_id, $stop_id)
{
    $academy_id = (int) $academy_id;
    $stop_id = (int) $stop_id;
    return array(
        'students' => ieum_vehicle_count_sql("
            select count(*) as cnt
              from " . IEUM_STUDENT_VEHICLE_TABLE . "
             where academy_id = '{$academy_id}'
               and stop_id = '{$stop_id}'
               and is_active = 1
        "),
        'logs' => ieum_vehicle_count_sql("
            select count(*) as cnt
              from " . IEUM_VEHICLE_BOARDING_TABLE . "
             where academy_id = '{$academy_id}'
               and stop_id = '{$stop_id}'
        "),
    );
}

ieum_vehicle_ensure_stop_location_columns();
ieum_vehicle_ensure_route_driver_pin_column();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '잘못된 요청입니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        if ($action === 'move_stop_up' || $action === 'move_stop_down') {
            $_POST['direction'] = $action === 'move_stop_down' ? 'down' : 'up';
            $action = 'move_stop';
        }
        $return_stop_id = isset($_POST['return_stop_id']) ? (int) $_POST['return_stop_id'] : 0;

        if ($action === 'move_stop') {
            $stop_id = isset($_POST['stop_id']) ? (int) $_POST['stop_id'] : 0;
            $direction = isset($_POST['direction']) ? trim($_POST['direction']) : 'up';
            $message = ieum_vehicle_move_stop_order($academy_id, $stop_id, $direction) ? '운행 지점 순서를 변경했습니다.' : '이동할 운행 지점이 없습니다.';
        } elseif ($action === 'normalize_stops') {
            ieum_vehicle_normalize_stop_order($academy_id);
            $message = '운행 지점 순서를 시간표 기준으로 정리했습니다.';
        } elseif ($action === 'delete_route') {
            $route_id = isset($_POST['route_id']) ? (int) $_POST['route_id'] : 0;
            if ($route_id <= 0) {
                $error = '삭제할 노선을 찾을 수 없습니다.';
            } else {
                $usage = ieum_vehicle_route_usage_counts($academy_id, $route_id);
                if ($usage['stops'] > 0 || $usage['students'] > 0 || $usage['logs'] > 0 || $usage['runs'] > 0) {
                    $error = '연결된 운행 지점, 원생 배정 또는 운행 기록이 있어 삭제할 수 없습니다. 먼저 연결을 정리한 뒤 삭제해주세요.';
                } else {
                    sql_query("delete from " . IEUM_VEHICLE_ROUTE_TABLE . " where route_id = '{$route_id}' and academy_id = '{$academy_id}' limit 1");
                    $message = '차량 노선을 삭제했습니다.';
                }
            }
        } elseif ($action === 'delete_stop') {
            $stop_id = isset($_POST['stop_id']) ? (int) $_POST['stop_id'] : 0;
            if ($stop_id <= 0) {
                $error = '삭제할 운행 지점을 찾을 수 없습니다.';
            } else {
                $usage = ieum_vehicle_stop_usage_counts($academy_id, $stop_id);
                if ($usage['students'] > 0 || $usage['logs'] > 0) {
                    $error = '원생 배정 또는 탑승 기록이 있어 삭제할 수 없습니다. 운영 중단만 하려면 사용 체크를 해제하고 저장해주세요.';
                } else {
                    sql_query("delete from " . IEUM_VEHICLE_STOP_TABLE . " where stop_id = '{$stop_id}' and academy_id = '{$academy_id}' limit 1");
                    $message = '운행 지점을 삭제했습니다.';
                }
            }
        } elseif ($action === 'save_route') {
            $route_id = isset($_POST['route_id']) ? (int) $_POST['route_id'] : 0;
            $route_type = isset($_POST['route_type']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['route_type'])) : 'both';
            $route_name = isset($_POST['route_name']) ? trim($_POST['route_name']) : '';
            $vehicle_label = isset($_POST['vehicle_label']) ? trim($_POST['vehicle_label']) : '';
            $driver_name = isset($_POST['driver_name']) ? trim($_POST['driver_name']) : '';
            $driver_phone = isset($_POST['driver_phone']) ? preg_replace('/[^0-9+\-]/', '', trim($_POST['driver_phone'])) : '';
            $driver_pin = isset($_POST['driver_pin']) ? preg_replace('/[^0-9]/', '', trim($_POST['driver_pin'])) : '';
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
                $driver_pin_sql = sql_escape_string($driver_pin);

                if ($route_id) {
                    sql_query("update " . IEUM_VEHICLE_ROUTE_TABLE . " set route_type = '{$route_type_sql}', route_name = '{$route_name_sql}', vehicle_label = '{$vehicle_label_sql}', driver_name = '{$driver_name_sql}', driver_phone = '{$driver_phone_sql}', driver_pin = '{$driver_pin_sql}', sort_order = '{$sort_order}', is_active = '{$is_active}', updated_at = '" . G5_TIME_YMDHIS . "' where route_id = '{$route_id}' and academy_id = '{$academy_id}'");
                    $message = '차량 노선을 수정했습니다.';
                } else {
                    sql_query("insert into " . IEUM_VEHICLE_ROUTE_TABLE . " set academy_id = '{$academy_id}', route_type = '{$route_type_sql}', route_name = '{$route_name_sql}', vehicle_label = '{$vehicle_label_sql}', driver_name = '{$driver_name_sql}', driver_phone = '{$driver_phone_sql}', driver_pin = '{$driver_pin_sql}', sort_order = '{$sort_order}', is_active = '{$is_active}', created_at = '" . G5_TIME_YMDHIS . "'");
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
                    sql_query("update " . IEUM_VEHICLE_STOP_TABLE . " set route_id = '{$route_id_sql}', stop_type = '{$stop_type_sql}', stop_name = '{$stop_name_sql}', stop_address = '{$stop_address_sql}', map_lat = {$map_lat_sql}, map_lng = {$map_lng_sql}, map_url = '{$map_url_sql}', stop_time = '{$stop_time_sql}', sort_order = '{$sort_order}', is_active = '{$is_active}', updated_at = '" . G5_TIME_YMDHIS . "' where stop_id = '{$stop_id}' and academy_id = '{$academy_id}'");
                    $message = '운행 지점을 수정했습니다.';
                } else {
                    sql_query("insert into " . IEUM_VEHICLE_STOP_TABLE . " set academy_id = '{$academy_id}', route_id = '{$route_id_sql}', stop_type = '{$stop_type_sql}', stop_name = '{$stop_name_sql}', stop_address = '{$stop_address_sql}', map_lat = {$map_lat_sql}, map_lng = {$map_lng_sql}, map_url = '{$map_url_sql}', stop_time = '{$stop_time_sql}', sort_order = '{$sort_order}', is_active = '{$is_active}', created_at = '" . G5_TIME_YMDHIS . "'");
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

$stop_move_flags = array();
$stop_groups = array();
foreach ($stop_rows as $idx => $stop) {
    $group_key = (int) $stop['route_id'] . '|' . $stop['stop_type'];
    if (!isset($stop_groups[$group_key])) {
        $stop_groups[$group_key] = array();
    }
    $stop_groups[$group_key][] = array(
        'stop_id' => (int) $stop['stop_id'],
        'index' => $idx,
    );
}
foreach ($stop_groups as $items) {
    $count = count($items);
    foreach ($items as $position => $item) {
        $stop_move_flags[$item['stop_id']] = array(
            'up' => $position > 0,
            'down' => $position < $count - 1,
        );
    }
}

$vehicle_stats = array(
    'active_routes' => count($route_options),
    'pickup_stops' => 0,
    'dropoff_stops' => 0,
    'unassigned_stops' => 0,
    'mapped_stops' => 0,
);
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
    if (!empty($stop['is_active'])) {
        if ($stop['stop_type'] === 'pickup') {
            $vehicle_stats['pickup_stops']++;
        } elseif ($stop['stop_type'] === 'dropoff') {
            $vehicle_stats['dropoff_stops']++;
        }
        if ($rid <= 0) {
            $vehicle_stats['unassigned_stops']++;
        }
        if ($stop['map_lat'] !== null && $stop['map_lng'] !== null && $stop['map_lat'] !== '' && $stop['map_lng'] !== '') {
            $vehicle_stats['mapped_stops']++;
        }
    }
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
<script src="https://oapi.map.naver.com/openapi/v3/maps.js?ncpKeyId=<?php echo get_text($map_settings['naver_client_id']); ?>"></script>
<?php } ?>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}
.wrap{max-width:1900px;margin:28px auto;padding:0 20px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px;overflow:hidden}
.vehicle-setup-grid{display:grid;grid-template-columns:minmax(360px,.95fr) minmax(420px,1.25fr);gap:18px;align-items:start}.vehicle-setup-grid .panel{margin-bottom:0}.setup-column{display:grid;gap:18px}.map-panel{min-height:100%}.map-stage{height:430px;border:1px solid #d9e2f1;border-radius:10px;background:#eef2f7;overflow:hidden;position:relative}.map-stage.empty{display:flex;align-items:center;justify-content:center;padding:22px;text-align:center;color:#667085;font-weight:900;line-height:1.6}.map-stage .map-empty-inner{max-width:420px}.map-tools{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:10px}.map-tools small{color:#667085}.map-results{display:none;margin-top:10px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;padding:10px;color:#344054;font-weight:800}.map-results.show{display:block}.map-results button{margin-top:8px}.map-pin-count{display:inline-flex;align-items:center;border-radius:999px;background:#eef5ff;color:#1769c2;padding:6px 10px;font-size:12px;font-weight:900}
h1{margin:0 0 8px;font-size:26px}.meta{color:#667085;margin-bottom:16px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}
input,select{width:100%;max-width:100%;min-width:0;border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}.grid.route,.grid.stop{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;align-items:center}.grid.route input[name="route_name"],.grid.stop select[name="route_id"],.grid.stop input[name="stop_name"],.grid.stop input[name="stop_address"],.grid.stop input[name="map_url"],.grid.stop .coord-grid,.grid.stop .map-results{grid-column:1/-1}.grid.route label,.grid.stop label{display:flex;align-items:center;gap:6px;min-height:40px;white-space:nowrap}.grid.route label input,.grid.stop label input{width:auto;flex:0 0 auto}.coord-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;min-width:0}.map-link{display:inline-flex;align-items:center;justify-content:center;min-height:32px;border-radius:999px;background:#eef5ff;color:#1769c2;text-decoration:none;font-size:12px;font-weight:900}.map-search{background:#eef5ff;border-color:#bfdbfe;color:#1769c2}.map-search.loading{opacity:.65;pointer-events:none}
.create-form{display:grid;gap:12px}.create-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;align-items:end}.route-create-form .create-row,.stop-create-form .create-row{grid-template-columns:repeat(2,minmax(0,1fr))}.form-field{display:grid;gap:6px;min-width:0}.field-label{font-size:12px;font-weight:900;color:#667085}.create-form details{border:1px solid #edf1f7;border-radius:8px;background:#f8fafc;padding:10px 12px}.create-form summary{cursor:pointer;font-weight:900;color:#475467}.create-form .advanced-inner{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:10px}.create-form .advanced-inner .wide{grid-column:1/-1}.create-form .check-line{display:flex;gap:6px;align-items:center;min-height:40px;white-space:nowrap}.create-form .check-line input{width:auto}.create-form .form-actions{display:flex;align-items:end}.create-form .form-actions .btn{width:100%}.stop-location-row{display:grid;grid-template-columns:minmax(0,1fr) 120px;gap:8px;align-items:end}.form-help{display:block;margin-top:4px;color:#667085;font-size:12px;line-height:1.4}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.print{background:#111827;border-color:#111827;color:#fff}.danger{background:#fff;border-color:#fecaca;color:#b91c1c}.danger:hover{background:#fef2f2}.map-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:8px}.map-status{display:inline-flex;align-items:center;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:900}.map-status.ok{background:#eaf8ef;color:#15703a}.map-status.miss{background:#fff7ed;color:#b45309}.map-status.manual{background:#eef5ff;color:#1769c2}.stop-type-toggle{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}.type-card{display:block;cursor:pointer;min-width:0}.type-card input{position:absolute;opacity:0;pointer-events:none;width:1px;height:1px}.type-card span{display:flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;font-weight:900;transition:.15s ease}.type-card input:checked+span{background:#1769c2;border-color:#1769c2;color:#fff;box-shadow:0 6px 14px rgba(23,105,194,.18)}.stop-time-field{display:grid;gap:6px;margin-top:8px}.stop-time-field span{font-size:12px;font-weight:900;color:#667085}.stop-time-field input{width:100%}
table{width:100%;min-width:980px;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center}td input,td select{min-width:110px}td input[type=checkbox]{width:auto;min-width:0}td.left input{margin-bottom:6px}th{background:#72829d;color:#fff}.panel>table{display:block;overflow-x:auto;white-space:nowrap}.left{text-align:left}.muted{color:#667085;font-size:12px;line-height:1.45}.inactive{background:#fafafa;color:#8a94a6}.section-title{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:0 0 14px;flex-wrap:wrap}
.flow-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.flow-card{border:1px solid #d9e2f1;border-radius:10px;overflow:hidden;background:#fff}.flow-head{display:flex;justify-content:space-between;gap:10px;padding:12px 14px;background:#15204a;color:#fff;font-weight:900}.flow-head small{color:#cbd5e1}.flow-list{list-style:none;margin:0;padding:0}.flow-list li{display:grid;grid-template-columns:72px 1fr auto;gap:10px;align-items:center;padding:11px 14px;border-top:1px solid #edf1f7}.flow-time{font-weight:900;color:#1769c2}.flow-name{font-weight:900}.flow-meta{display:block;margin-top:3px;color:#667085;font-size:12px}.flow-empty{padding:18px;color:#667085;text-align:center;background:#f8fafc}.flow-badge{display:inline-flex;align-items:center;border-radius:999px;background:#eef5ff;color:#1769c2;padding:4px 8px;font-size:12px;font-weight:900;text-decoration:none}
.api-hint{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;padding:12px 14px;margin-bottom:18px;color:#344054}.api-hint strong{color:#1769c2}.api-hint a{flex:0 0 auto}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-bottom:18px}.summary-card{border:1px solid #d9e2f1;border-radius:10px;background:#fff;padding:14px;min-width:0}.summary-card.warning{border-color:#f3c16b;background:#fffaf0}.summary-card .label{color:#667085;font-size:13px;font-weight:800}.summary-card strong{display:block;margin-top:6px;font-size:26px;line-height:1.25;word-break:keep-all;overflow-wrap:anywhere}.summary-card small{display:block;margin-top:4px;color:#667085}.setup-note{display:flex;gap:8px;align-items:flex-start;border:1px solid #d9e8ff;background:#f7fbff;border-radius:10px;padding:12px 14px;margin:0 0 14px;color:#344054;line-height:1.5}.setup-note b{display:inline-flex;align-items:center;justify-content:center;flex:0 0 24px;height:24px;border-radius:999px;background:#1769c2;color:#fff;font-size:13px}.order-actions{display:flex;gap:4px;justify-content:center}.order-actions form{display:inline}.mini{min-height:30px;padding:4px 8px;font-size:13px}.section-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.table-wrap{width:100%;overflow-x:auto}
.stop-card-list{display:grid;gap:12px}.stop-card{border:1px solid #d8dee9;border-radius:10px;background:#fff;overflow:hidden}.stop-card.inactive{background:#fafafa;color:#8a94a6}.hidden-move-form{display:none}.stop-card-form{display:grid;grid-template-columns:230px minmax(320px,1.4fr) minmax(240px,1fr) minmax(220px,1fr);gap:12px;align-items:start;padding:14px}.stop-field{display:grid;gap:7px;min-width:0}.stop-label{display:inline-flex;align-items:center;width:max-content;max-width:100%;border-radius:999px;background:#eef5ff;color:#1769c2;border:1px solid #d9e7ff;padding:4px 9px;font-size:12px;font-weight:900;line-height:1}.stop-kind{display:grid;grid-template-columns:minmax(72px,.8fr) minmax(145px,1fr);gap:8px;align-items:center}.stop-place{display:grid;gap:8px;min-width:0}.stop-address{font-size:13px;color:#667085;line-height:1.4}.stop-address input{margin-top:6px}.stop-route{display:grid;gap:8px;min-width:0}.vehicle-label{display:inline-flex;width:max-content;max-width:100%;border-radius:999px;background:#eef5ff;color:#1769c2;padding:6px 10px;font-size:12px;font-weight:900;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.map-summary{display:flex;gap:6px;align-items:center;flex-wrap:wrap;min-width:0}.map-advanced{margin-top:8px}.map-advanced summary{cursor:pointer;color:#475467;font-size:12px;font-weight:900}.map-advanced .advanced-inner{display:grid;gap:8px;margin-top:8px}.map-advanced input[name=map_url],.map-advanced .coord-grid{font-size:13px}.stop-actions{grid-column:1/-1;display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap;border-top:1px solid #edf1f7;padding-top:12px}.stop-actions label{display:inline-flex;gap:6px;align-items:center;white-space:nowrap}.stop-actions input[type=checkbox]{width:auto}.stop-sort-action{display:inline-flex;gap:6px;align-items:center;color:#667085;font-size:12px;font-weight:900}.stop-sort-action input{width:80px}.stop-move-actions,.stop-save-actions,.route-actions{display:flex;gap:6px;align-items:center;justify-content:center;flex-wrap:wrap}.stop-move-actions form{display:inline}.stop-move-actions .btn{min-width:58px}.stop-move-actions .btn:disabled{opacity:.38;cursor:not-allowed;background:#f3f4f6;color:#94a3b8}.route-status label{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-width:72px;white-space:nowrap}.route-status input[type=checkbox]{flex:0 0 auto;width:16px;min-width:0}.route-actions{min-width:126px;flex-wrap:nowrap}.route-actions .btn{min-width:58px;white-space:nowrap}.vehicle-route-table th:nth-child(8),.vehicle-route-table td.route-status{min-width:92px}.vehicle-route-table th:last-child,.vehicle-route-table td:last-child{min-width:148px}.stop-empty{border:1px dashed #cfd6df;border-radius:10px;padding:24px;text-align:center;color:#667085;background:#f8fafc}
@media(max-width:980px){.vehicle-setup-grid{grid-template-columns:1fr}.grid.route,.grid.stop{grid-template-columns:repeat(auto-fit,minmax(140px,1fr))}.grid.route input[name="route_name"],.grid.stop select[name="route_id"],.grid.stop input[name="stop_name"],.grid.stop input[name="stop_address"],.grid.stop input[name="map_url"],.grid.stop .coord-grid,.grid.stop .map-results{grid-column:1/-1}.create-row,.route-create-form .create-row,.stop-create-form .create-row,.create-form .advanced-inner,.stop-location-row{grid-template-columns:1fr}table{white-space:nowrap}.section-title{align-items:flex-start;flex-direction:column}.map-stage{height:340px}}
@media(max-width:1200px){.stop-card-form{grid-template-columns:1fr 1fr}.stop-actions{justify-content:flex-start}}
@media(max-width:1100px){.panel>table.vehicle-route-table{display:block;min-width:0;overflow:visible;white-space:normal}.vehicle-route-table thead{display:none}.vehicle-route-table tbody{display:block}.vehicle-route-table tr{display:block;border:1px solid #d8dee9;border-radius:10px;margin-bottom:12px;padding:10px;background:#fff}.vehicle-route-table tr.inactive{background:#fafafa}.vehicle-route-table td{display:grid;grid-template-columns:96px minmax(0,1fr);gap:10px;align-items:center;border:0;border-top:1px solid #edf1f7;text-align:left;padding:10px 0}.vehicle-route-table td:first-child{border-top:0}.vehicle-route-table td:before{content:attr(data-label);font-size:13px;font-weight:900;color:#667085}.vehicle-route-table td input,.vehicle-route-table td select{min-width:0}.vehicle-route-table .order-actions{justify-content:flex-start}}
@media(max-width:760px){.flow-grid,.summary-grid,.stop-card-form{grid-template-columns:1fr}.flow-list li{grid-template-columns:60px 1fr}.api-hint{align-items:flex-start;flex-direction:column}.api-hint a{width:100%}.grid.route,.grid.stop,.coord-grid{grid-template-columns:1fr}.map-stage{height:300px}}
</style>
<style>
body.vehicles-page-tune .wrap{max-width:none!important;margin:0 86px 0 248px!important;padding:84px 28px 42px!important}
body.vehicles-page-tune .top,body.vehicles-page-tune .ieum-subnav-wrap{display:none!important}
body.vehicles-page-tune .panel,body.vehicles-page-tune .summary-card,body.vehicles-page-tune .flow-card{border-radius:18px;box-shadow:0 12px 28px rgba(15,23,42,.06)}
body.vehicles-page-tune h1{font-size:30px;letter-spacing:0}
body.vehicles-page-tune .api-hint,body.vehicles-page-tune .setup-note{border-radius:14px}
body.vehicles-page-tune .stop-card{border-radius:18px;border-color:#dbe3ef;background:#fff}
body.vehicles-page-tune .stop-card-form{grid-template-columns:210px minmax(300px,1.1fr) minmax(270px,1fr) minmax(250px,1fr);gap:16px;padding:18px}
body.vehicles-page-tune .stop-label{background:#eef2f7;color:#344054;border-color:#d9e1ec;font-size:13px}
body.vehicles-page-tune .stop-operate-field{background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:12px}
body.vehicles-page-tune .stop-operate-field .stop-label{background:#e7efff;color:#1769c2;border-color:#cfe0ff}
body.vehicles-page-tune .type-card span{border-radius:12px;min-height:44px}
body.vehicles-page-tune .stop-actions{margin:8px -18px -18px;padding:14px 18px;background:#fbfcff;border-top:1px solid #e4eaf2}
body.vehicles-page-tune .stop-sort-action{background:#fff;border:1px solid #d9e1ec;border-radius:12px;padding:7px 10px}
body.vehicles-page-tune .stop-sort-action input{height:36px;text-align:center;font-weight:900}
body.vehicles-page-tune .stop-sort-action input.is-touched{border-color:#1769c2;background:#eff6ff;color:#0f3f88}
body.vehicles-page-tune .stop-move-actions{background:#eef2f7;border:1px solid #d9e1ec;border-radius:12px;padding:4px}
body.vehicles-page-tune .stop-move-actions .btn{min-width:38px;min-height:34px;border-radius:9px;background:#fff}
body.vehicles-page-tune .stop-move-actions .btn.is-touched{background:#e7f0ff;border-color:#1769c2;color:#1769c2}
body.vehicles-page-tune .stop-save-actions{gap:8px}
body.vehicles-page-tune .route-status label{display:inline-flex;background:#f8fafc;border:1px solid #d9dee7;border-radius:12px;padding:8px 10px;min-width:86px;line-height:1;white-space:nowrap}
body.vehicles-page-tune .route-actions{gap:8px}
body.vehicles-page-tune .vehicle-route-table td:last-child{white-space:nowrap}
body.vehicles-page-tune .vehicle-route-table{display:block!important;width:100%!important;min-width:0!important;max-width:100%!important;border:0;background:transparent;box-sizing:border-box}
body.vehicles-page-tune .vehicle-route-table thead{display:none}
body.vehicles-page-tune .vehicle-route-table tbody{display:grid!important;gap:12px;width:100%!important;min-width:0!important;max-width:100%!important}
body.vehicles-page-tune .vehicle-route-table tr{display:grid!important;grid-template-columns:150px minmax(180px,1fr) 130px 150px 150px 130px 90px 110px 150px;gap:10px;align-items:center;width:100%!important;min-width:0!important;max-width:100%!important;border:1px solid #dbe3ef;border-radius:16px;background:#fff;padding:14px;box-sizing:border-box}
body.vehicles-page-tune .vehicle-route-table td{display:grid;gap:6px;border:0!important;padding:0!important;text-align:left}
body.vehicles-page-tune .vehicle-route-table td:before{content:attr(data-label);font-size:12px;font-weight:900;color:#64748b}
body.vehicles-page-tune .vehicle-route-table td input,
body.vehicles-page-tune .vehicle-route-table td select{width:100%;min-width:0}
body.vehicles-page-tune .vehicle-route-table .route-status label{min-width:0;justify-content:flex-start}
body.vehicles-page-tune .vehicle-route-table .route-status input[type=checkbox]{width:16px!important;min-width:16px!important;flex:0 0 16px}
body.vehicles-page-tune .vehicle-route-table .route-actions{justify-content:flex-start;align-items:center;flex-wrap:nowrap}
body.vehicles-page-tune .vehicle-route-table .route-actions .btn{min-width:58px;min-height:38px;padding:8px 12px}
body.vehicles-page-tune .vehicle-route-table td[data-label="관리"]{overflow:visible}
@media(max-width:1500px){body.vehicles-page-tune .vehicle-route-table tr{grid-template-columns:repeat(3,minmax(0,1fr))}body.vehicles-page-tune .vehicle-route-table td:last-child{grid-column:1/-1}}
@media(max-width:1500px){body.vehicles-page-tune .stop-card-form{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}body.vehicles-page-tune .stop-actions{justify-content:flex-start}}
@media(max-width:1200px){body.vehicles-page-tune .wrap{margin-left:248px!important;margin-right:86px!important}}
body.ieum-side-layout.ieum-dashboard-page.vehicles-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    --ieum-shell-top:#fff;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.vehicles-page-tune .ieum-side{
    width:260px!important;
    background:#fff!important;
    border-right:1px solid #e5e7eb!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.vehicles-page-tune .side-brand{height:144px!important;padding:0 28px!important;align-items:center!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.vehicles-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.vehicles-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.vehicles-page-tune .side-search,
body.ieum-side-layout.ieum-dashboard-page.vehicles-page-tune .ieum-right-rail{display:none!important}
.vehicles-page-tune .side-nav{padding:0 14px 22px!important}
.vehicles-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}
.vehicles-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}
.vehicles-page-tune .ieum-shell-top{
    left:260px!important;
    right:0!important;
    height:64px!important;
    background:#fff!important;
    border-bottom:1px solid #eef2f7!important;
    color:#0f172a!important;
    box-shadow:none!important;
}
.vehicles-page-tune .ieum-shell-link,
.vehicles-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}
.vehicles-page-tune .ieum-shell-link::before{display:none!important}
.vehicles-page-tune .ieum-shell-meta{color:#0f172a!important}
.vehicles-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.vehicles-page-tune .dashboard-shell-divider,
.vehicles-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.vehicles-page-tune .wrap{
    max-width:none!important;
    width:auto!important;
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.vehicles-page-tune{--ieum-side-width:0px}
    .vehicles-page-tune .ieum-shell-top{left:0!important}
    body.ieum-side-layout.ieum-dashboard-page.vehicles-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page vehicles-page-tune">
<?php echo ieum_admin_header('vehicles', 'side'); ?>
<main class="wrap">
    <h1>차량 관리</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 차량과 노선, 픽업/하차 지점을 관리합니다. 원생별 차량 배정과 기사님 차량 일지에 연결됩니다.</div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <div class="api-hint">
        <div>현재 지도 방식: <strong><?php echo get_text(ieum_map_mode_label($map_settings)); ?></strong> · 지도 API는 본사 공통 설정을 사용합니다.</div>
        <?php if ($is_admin === 'super') { ?>
        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/map_settings.php">지도 API 설정</a>
        <?php } ?>
    </div>

    <section class="summary-grid">
        <article class="summary-card">
            <div class="label">사용 노선</div>
            <strong><?php echo number_format((int) $vehicle_stats['active_routes']); ?>개</strong>
            <small>1호차, 2호차처럼 실제 운행 코스 기준</small>
        </article>
        <article class="summary-card">
            <div class="label">픽업 지점</div>
            <strong><?php echo number_format((int) $vehicle_stats['pickup_stops']); ?>곳</strong>
            <small>등원 차량에서 태우는 장소</small>
        </article>
        <article class="summary-card">
            <div class="label">하차 지점</div>
            <strong><?php echo number_format((int) $vehicle_stats['dropoff_stops']); ?>곳</strong>
            <small>하원 차량에서 내려주는 장소</small>
        </article>
        <article class="summary-card">
            <div class="label">지도 등록</div>
            <strong><?php echo number_format((int) $vehicle_stats['mapped_stops']); ?>곳</strong>
            <small>좌표가 입력된 운행 지점</small>
        </article>
        <article class="summary-card <?php echo $vehicle_stats['unassigned_stops'] > 0 ? 'warning' : ''; ?>">
            <div class="label">노선 미지정</div>
            <strong><?php echo number_format((int) $vehicle_stats['unassigned_stops']); ?>곳</strong>
            <small>노선 연결이 필요한 지점</small>
        </article>
    </section>

    <section class="panel">
        <div class="section-title">
            <h2>노선별 운행 동선</h2>
            <span class="muted">노선에 연결된 운행 지점을 시간순으로 확인합니다.</span>
        </div>
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

    <section class="vehicle-setup-grid">
    <div class="setup-column">
    <section class="panel">
        <div class="section-title">
            <h2>노선 등록</h2>
            <div>
                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/vehicle_boarding.php">탑승 확인</a>
                <a class="btn print" href="<?php echo IEUM_URL; ?>/admin/vehicle_journal.php" target="_blank" rel="noopener">차량 일지 인쇄</a>
                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/vehicle_driver_login.php?academy_code=<?php echo urlencode($academy['academy_code']); ?>" target="_blank" rel="noopener">기사님 접속</a>
            </div>
        </div>
        <div class="setup-note"><b>1</b><span>차량 운행은 먼저 노선을 만들고, 아래 운행 지점에서 그 노선을 선택해 장소와 시간을 붙입니다.</span></div>
        <form method="post" class="create-form route-create-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save_route">
            <div class="create-row">
                <label class="form-field">
                    <span class="field-label">노선 구분</span>
                    <select name="route_type">
                        <?php foreach (ieum_vehicle_type_options() as $value => $label) { ?>
                        <option value="<?php echo get_text($value); ?>"><?php echo get_text($label); ?></option>
                        <?php } ?>
                    </select>
                </label>
                <label class="form-field">
                    <span class="field-label">노선명</span>
                    <input type="text" name="route_name" placeholder="예: 1호차 학교 픽업 A코스" maxlength="80" required>
                </label>
                <label class="form-field">
                    <span class="field-label">차량명</span>
                    <input type="text" name="vehicle_label" placeholder="예: 1호차" maxlength="50">
                </label>
                <div class="form-actions">
                    <button type="submit" class="btn primary">노선 추가</button>
                </div>
            </div>
            <details>
                <summary>기사님 접속 / 정렬 설정</summary>
                <div class="advanced-inner">
                    <label class="form-field">
                        <span class="field-label">기사/사범</span>
                        <input type="text" name="driver_name" placeholder="기사님 또는 사범님 이름" maxlength="50">
                    </label>
                    <label class="form-field">
                        <span class="field-label">연락처</span>
                        <input type="text" name="driver_phone" placeholder="010-0000-0000" maxlength="30">
                    </label>
                    <label class="form-field">
                        <span class="field-label">기사 PIN</span>
                        <input type="text" name="driver_pin" inputmode="numeric" placeholder="기사앱 접속 PIN" maxlength="8">
                    </label>
                    <label class="form-field">
                        <span class="field-label">표시 순서</span>
                        <input type="number" name="sort_order" placeholder="숫자가 낮을수록 먼저 표시" min="0">
                    </label>
                    <label class="check-line"><input type="checkbox" name="is_active" value="1" checked> 사용 중인 노선</label>
                </div>
            </details>
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
        <div class="setup-note"><b>2</b><span>픽업/하차 장소를 등록한 뒤 위에서 만든 노선을 선택하면 차량 일지와 탑승 확인에 자동으로 묶입니다.</span></div>
        <form method="post" class="create-form stop-create-form" id="newStopForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save_stop">
            <div class="create-row">
                <div class="form-field">
                    <span class="field-label">운행 구분</span>
                    <div class="stop-type-toggle">
                        <?php foreach (ieum_stop_type_options() as $value => $label) { ?>
                        <label class="type-card">
                            <input type="radio" name="stop_type" value="<?php echo get_text($value); ?>" <?php echo $value === 'pickup' ? 'checked' : ''; ?>>
                            <span><?php echo get_text($label); ?></span>
                        </label>
                        <?php } ?>
                    </div>
                </div>
                <label class="form-field">
                    <span class="field-label">노선</span>
                    <select name="route_id">
                        <option value="0">노선 선택 안함</option>
                        <?php foreach ($route_options as $route) { ?>
                        <option value="<?php echo (int) $route['route_id']; ?>"><?php echo get_text(($route['vehicle_label'] ? $route['vehicle_label'] . ' · ' : '') . $route['route_name']); ?></option>
                        <?php } ?>
                    </select>
                </label>
                <label class="form-field">
                    <span class="field-label">장소명</span>
                    <input type="text" name="stop_name" placeholder="예: 아이이음초등학교" maxlength="100" required>
                </label>
                <label class="form-field">
                    <span class="field-label">시간</span>
                    <input type="time" name="stop_time" value="14:10" required>
                </label>
                <div class="form-actions">
                    <button type="submit" class="btn primary">지점 추가</button>
                </div>
            </div>
            <div class="stop-location-row">
                <label class="form-field">
                    <span class="field-label">주소 / 기사님 참고 위치</span>
                    <input type="text" name="stop_address" placeholder="예: 아이이음초등학교 정문, 아파트 1004동" maxlength="160">
                </label>
                <button type="button" class="btn map-search">주소 검색</button>
            </div>
            <div class="map-results" aria-live="polite"></div>
            <details>
                <summary>고급 지도 / 정렬 정보</summary>
                <div class="advanced-inner">
                    <label class="form-field wide">
                        <span class="field-label">지도 링크</span>
                        <input type="text" name="map_url" placeholder="주소 검색 후 자동 입력됩니다." maxlength="255">
                    </label>
                    <div class="coord-grid wide">
                        <input type="text" name="map_lat" placeholder="위도">
                        <input type="text" name="map_lng" placeholder="경도">
                    </div>
                    <label class="form-field">
                        <span class="field-label">표시 순서</span>
                        <input type="number" name="sort_order" placeholder="숫자가 낮을수록 먼저 표시" min="0">
                    </label>
                    <label class="check-line"><input type="checkbox" name="is_active" value="1" checked> 사용 중인 지점</label>
                </div>
            </details>
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
        <h2>운행 지점</h2>
        <div class="stop-card-list">
            <?php $i = 0; foreach ($stop_rows as $row) { $i++; $stop_id = (int) $row['stop_id']; $move_flag = isset($stop_move_flags[$stop_id]) ? $stop_move_flags[$stop_id] : array('up' => false, 'down' => false); ?>
            <article class="stop-card <?php echo $row['is_active'] ? '' : 'inactive'; ?>" id="stop-card-<?php echo (int) $row['stop_id']; ?>">
                <form method="post" class="stop-card-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="stop_id" value="<?php echo (int) $row['stop_id']; ?>">
                    <input type="hidden" name="return_stop_id" value="<?php echo (int) $row['stop_id']; ?>">
                    <div class="stop-field stop-operate-field">
                        <span class="stop-label">운행</span>
                        <div class="stop-type-toggle">
                            <?php foreach (ieum_stop_type_options() as $value => $label) { ?>
                            <label class="type-card">
                                <input type="radio" name="stop_type" value="<?php echo get_text($value); ?>" <?php echo $row['stop_type'] === $value ? 'checked' : ''; ?>>
                                <span><?php echo get_text($label); ?></span>
                            </label>
                            <?php } ?>
                        </div>
                        <label class="stop-time-field">
                            <span>시간</span>
                            <input type="time" name="stop_time" value="<?php echo get_text($row['stop_time']); ?>">
                        </label>
                    </div>
                    <div class="stop-field">
                        <span class="stop-label">장소</span>
                        <div class="stop-place">
                            <input type="text" name="stop_name" value="<?php echo get_text($row['stop_name']); ?>" maxlength="100">
                            <div class="stop-address">
                                <?php echo !empty($row['stop_address']) ? get_text($row['stop_address']) : '주소 또는 기사님 참고 위치를 입력하세요.'; ?>
                                <input type="text" name="stop_address" value="<?php echo get_text(isset($row['stop_address']) ? $row['stop_address'] : ''); ?>" maxlength="160" placeholder="주소 또는 기사님 참고 위치">
                            </div>
                        </div>
                    </div>
                    <div class="stop-field">
                        <span class="stop-label">노선/차량</span>
                        <div class="stop-route">
                            <select name="route_id">
                                <option value="0">노선 선택 안함</option>
                                <?php foreach ($route_options as $route) { ?>
                                <option value="<?php echo (int) $route['route_id']; ?>" <?php echo get_selected((int) $row['route_id'], (int) $route['route_id']); ?>><?php echo get_text(($route['vehicle_label'] ? $route['vehicle_label'] . ' · ' : '') . $route['route_name']); ?></option>
                                <?php } ?>
                            </select>
                            <span class="vehicle-label"><?php echo get_text($row['vehicle_label'] ?: '차량 미지정'); ?></span>
                        </div>
                    </div>
                    <div class="stop-field">
                        <span class="stop-label">지도 상태</span>
                        <?php
                        $map_href = ieum_vehicle_map_href($row);
                        $has_coord = isset($row['map_lat'], $row['map_lng']) && $row['map_lat'] !== '' && $row['map_lng'] !== '';
                        ?>
                        <div class="map-summary">
                            <?php if ($has_coord) { ?>
                            <span class="map-status ok">지도 등록됨</span>
                            <?php } elseif ($map_href !== '') { ?>
                            <span class="map-status manual">주소 확인 필요</span>
                            <?php } else { ?>
                            <span class="map-status miss">지도 미등록</span>
                            <?php } ?>
                            <?php if ($map_href !== '') { ?>
                            <a class="map-link" href="<?php echo get_text($map_href); ?>" target="_blank" rel="noopener">지도 확인</a>
                            <?php } ?>
                            <button type="button" class="btn map-search mini">주소 검색</button>
                        </div>
                        <details class="map-advanced">
                            <summary>고급 지도정보</summary>
                            <div class="advanced-inner">
                                <input type="text" name="map_url" value="<?php echo get_text(isset($row['map_url']) ? $row['map_url'] : ''); ?>" maxlength="255" placeholder="지도 링크">
                                <div class="coord-grid">
                                    <input type="text" name="map_lat" value="<?php echo get_text(isset($row['map_lat']) ? $row['map_lat'] : ''); ?>" placeholder="위도">
                                    <input type="text" name="map_lng" value="<?php echo get_text(isset($row['map_lng']) ? $row['map_lng'] : ''); ?>" placeholder="경도">
                                </div>
                            </div>
                        </details>
                        <div class="map-results" aria-live="polite"></div>
                    </div>
                    <div class="stop-actions">
                        <label class="stop-sort-action">순서 <input type="number" name="sort_order" value="<?php echo (int) $row['sort_order']; ?>" min="0"></label>
                        <div class="stop-move-actions" aria-label="운행 지점 순서 빠른 변경">
                            <button type="button" class="btn mini js-stop-move" data-delta="-10" title="순서 숫자 줄이기" <?php echo $move_flag['up'] ? '' : 'disabled'; ?>>↑</button>
                            <button type="button" class="btn mini js-stop-move" data-delta="10" title="순서 숫자 늘리기" <?php echo $move_flag['down'] ? '' : 'disabled'; ?>>↓</button>
                        </div>
                        <div class="stop-save-actions">
                            <label><input type="checkbox" name="is_active" value="1" <?php echo $row['is_active'] ? 'checked' : ''; ?>> 사용</label>
                            <button type="submit" name="action" value="save_stop" class="btn">저장</button>
                            <button type="submit" name="action" value="delete_stop" class="btn danger" onclick="return confirm('이 운행 지점을 삭제할까요? 원생 배정이나 탑승 기록이 있으면 삭제할 수 없습니다.');">삭제</button>
                        </div>
                    </div>
                </form>
            </article>
            <?php } ?>
            <?php if ($i === 0) { ?><div class="stop-empty">등록된 운행 지점이 없습니다.</div><?php } ?>
        </div>
    </section>

    <section class="panel">
        <h2>노선</h2>
        <table class="vehicle-route-table">
            <thead><tr><th>구분</th><th>노선명</th><th>차량</th><th>담당</th><th>연락처</th><th>기사 PIN</th><th>배정 원생</th><th>상태</th><th>관리</th></tr></thead>
            <tbody>
            <?php $i = 0; while ($row = sql_fetch_array($routes)) { $i++; ?>
                <tr class="<?php echo $row['is_active'] ? '' : 'inactive'; ?>">
                    <?php $route_form_id = 'route-form-' . (int) $row['route_id']; ?>
                    <td data-label="구분">
                        <select name="route_type" form="<?php echo $route_form_id; ?>">
                            <?php foreach (ieum_vehicle_type_options() as $value => $label) { ?>
                            <option value="<?php echo get_text($value); ?>" <?php echo get_selected($row['route_type'], $value); ?>><?php echo get_text($label); ?></option>
                            <?php } ?>
                        </select>
                    </td>
                    <td data-label="노선명"><input type="text" name="route_name" form="<?php echo $route_form_id; ?>" value="<?php echo get_text($row['route_name']); ?>" maxlength="80"></td>
                    <td data-label="차량"><input type="text" name="vehicle_label" form="<?php echo $route_form_id; ?>" value="<?php echo get_text($row['vehicle_label']); ?>" maxlength="50"></td>
                    <td data-label="담당"><input type="text" name="driver_name" form="<?php echo $route_form_id; ?>" value="<?php echo get_text($row['driver_name']); ?>" maxlength="50"></td>
                    <td data-label="연락처"><input type="text" name="driver_phone" form="<?php echo $route_form_id; ?>" value="<?php echo get_text($row['driver_phone']); ?>" maxlength="30"></td>
                    <td data-label="기사 PIN"><input type="text" name="driver_pin" form="<?php echo $route_form_id; ?>" inputmode="numeric" value="<?php echo get_text(isset($row['driver_pin']) ? $row['driver_pin'] : ''); ?>" maxlength="8" placeholder="숫자"></td>
                    <td data-label="배정 원생"><?php echo number_format((int) $row['student_count']); ?>명</td>
                    <td data-label="상태" class="route-status"><label><input type="checkbox" name="is_active" form="<?php echo $route_form_id; ?>" value="1" <?php echo $row['is_active'] ? 'checked' : ''; ?>> 사용</label></td>
                    <td data-label="관리">
                        <form id="<?php echo $route_form_id; ?>" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="route_id" value="<?php echo (int) $row['route_id']; ?>">
                            <input type="hidden" name="sort_order" value="<?php echo (int) $row['sort_order']; ?>">
                        </form>
                        <div class="route-actions">
                            <button type="submit" name="action" value="save_route" form="<?php echo $route_form_id; ?>" class="btn">저장</button>
                            <button type="submit" name="action" value="delete_route" form="<?php echo $route_form_id; ?>" class="btn danger" onclick="return confirm('이 노선을 삭제할까요? 연결된 운행 지점, 원생 배정 또는 기록이 있으면 삭제할 수 없습니다.');">삭제</button>
                        </div>
                    </td>
                </tr>
            <?php } ?>
            <?php if ($i === 0) { ?><tr><td colspan="9">등록된 차량 노선이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
    </section>
</main>
<script>
(function(){
    var rootSelector = '.vehicles-page-tune.ieum-dashboard-page';
    var brandText = document.querySelector(rootSelector + ' .side-brand span:last-child');
    if (brandText) brandText.textContent = <?php echo json_encode($academy['academy_name']); ?>;
    var homeLink = document.querySelector(rootSelector + ' .ieum-shell-link');
    if (homeLink) homeLink.textContent = '아이이음 교육페이지';
    var meta = document.querySelector(rootSelector + ' .ieum-shell-meta');
    if (meta) {
        var now = new Date();
        var hh = String(now.getHours()).padStart(2, '0');
        var mm = String(now.getMinutes()).padStart(2, '0');
        meta.innerHTML = ''
            + '<span class="dashboard-shell-meta-inner">'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php">개발지원센터</a>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span class="dashboard-shell-help-group">'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#qna">Q&A</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#faq">자주하는 질문</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#contact">문의하기</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#chatbot">AI 챗봇</a>'
            + '</span>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span><?php echo get_text($academy['academy_name']); ?></span>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span class="dashboard-shell-clock">' + hh + ':' + mm + '</span>'
            + '</span>';
    }
})();
</script>
<script>
const ieumVehicleMapConfig = {
    canUseDynamicMap: <?php echo $can_use_dynamic_map ? 'true' : 'false'; ?>,
    canUseGeocoding: <?php echo $can_use_geocoding ? 'true' : 'false'; ?>,
    geocodeUrl: '<?php echo IEUM_URL; ?>/admin/map_geocode.php',
    markers: <?php echo json_encode($map_markers, JSON_UNESCAPED_UNICODE); ?>,
    returnStopId: <?php echo (int) $return_stop_id; ?>
};

(function () {
    let vehicleMap = null;
    let markerObjects = [];

    document.addEventListener('click', (event) => {
        const button = event.target.closest('.js-stop-move');
        if (!button) return;
        event.preventDefault();
        const form = button.closest('.stop-card-form');
        const input = form ? form.querySelector('.stop-sort-action input[name="sort_order"]') : null;
        if (!input) return;
        const delta = parseInt(button.dataset.delta || '0', 10);
        const current = parseInt(input.value || '0', 10) || 0;
        input.value = Math.max(0, current + delta);
        input.dispatchEvent(new Event('change', { bubbles: true }));
        button.classList.add('is-touched');
        input.classList.add('is-touched');
    });

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
            title: title || '운행 지점'
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
            alert('장소명 또는 주소를 입력하세요.');
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
                showResult(form, `${data.message || '주소 검색에 실패했습니다.'}<br><button type="button" class="btn mini open-map-link">네이버 지도에서 열기</button>`);
                return;
            }
            setField(form, 'stop_address', data.address || query);
            setField(form, 'map_lat', data.lat || '');
            setField(form, 'map_lng', data.lng || '');
            setField(form, 'map_url', data.mapUrl || fallbackUrl);
            focusMap(data.lat, data.lng, data.address || query);
            showResult(form, `주소 확인: <strong>${data.address || query}</strong><br>좌표와 지도 링크가 입력되었습니다.`);
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
    if (ieumVehicleMapConfig.returnStopId) {
        const target = document.getElementById('stop-card-' + ieumVehicleMapConfig.returnStopId);
        if (target) {
            requestAnimationFrame(() => target.scrollIntoView({ block: 'center' }));
        }
    }
})();
</script>
</body>
</html>
