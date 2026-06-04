<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

require_once IEUM_PATH . '/lib/response.php';
require_once IEUM_PATH . '/lib/sms_queue.php';

function ieum_vehicle_api_input()
{
    static $input = null;
    if ($input !== null) {
        return $input;
    }

    $input = $_POST;
    $raw = file_get_contents('php://input');
    $content_type = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string) $_SERVER['CONTENT_TYPE']) : '';
    if ($raw !== '' && strpos($content_type, 'application/json') !== false) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $input = array_merge($input, $json);
        }
    }

    return $input;
}

function ieum_vehicle_api_value($name, $default = '')
{
    $input = ieum_vehicle_api_input();
    if (isset($input[$name])) {
        return $input[$name];
    }
    if (isset($_GET[$name])) {
        return $_GET[$name];
    }
    return $default;
}

function ieum_vehicle_api_secret()
{
    $parts = array('ieum-vehicle-driver-api');
    if (defined('IEUM_SMS_GATEWAY_TOKEN')) {
        $parts[] = IEUM_SMS_GATEWAY_TOKEN;
    }
    if (defined('IEUM_PROJECT_STATUS_TOKEN')) {
        $parts[] = IEUM_PROJECT_STATUS_TOKEN;
    }
    if (defined('G5_MYSQL_PASSWORD')) {
        $parts[] = G5_MYSQL_PASSWORD;
    }

    return hash('sha256', implode('|', $parts));
}

function ieum_vehicle_api_base64url_encode($value)
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function ieum_vehicle_api_base64url_decode($value)
{
    $value = strtr($value, '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad) {
        $value .= str_repeat('=', 4 - $pad);
    }

    return base64_decode($value);
}

function ieum_vehicle_driver_token($academy_id, $route_id, $expires_at = 0)
{
    $payload = array(
        'a' => (int) $academy_id,
        'r' => (int) $route_id,
        'e' => $expires_at ? (int) $expires_at : strtotime('+30 days'),
        'iat' => time(),
    );
    $body = ieum_vehicle_api_base64url_encode(json_encode($payload));
    $signature = hash_hmac('sha256', $body, ieum_vehicle_api_secret());

    return $body . '.' . $signature;
}

function ieum_vehicle_driver_token_payload($token)
{
    $token = trim((string) $token);
    if ($token === '' || strpos($token, '.') === false) {
        return null;
    }

    list($body, $signature) = explode('.', $token, 2);
    $expected = hash_hmac('sha256', $body, ieum_vehicle_api_secret());
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $payload = json_decode(ieum_vehicle_api_base64url_decode($body), true);
    if (!is_array($payload) || empty($payload['a']) || empty($payload['r']) || empty($payload['e'])) {
        return null;
    }
    if ((int) $payload['e'] < time()) {
        return null;
    }

    return $payload;
}

function ieum_vehicle_request_token()
{
    $headers = function_exists('getallheaders') ? getallheaders() : array();
    foreach ($headers as $name => $value) {
        $lower = strtolower($name);
        if ($lower === 'authorization' && preg_match('/Bearer\s+(.+)/i', $value, $matches)) {
            return trim($matches[1]);
        }
        if ($lower === 'x-ieum-driver-token') {
            return trim($value);
        }
    }

    $server_auth = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $server_auth = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $server_auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if ($server_auth !== '' && preg_match('/Bearer\s+(.+)/i', $server_auth, $matches)) {
        return trim($matches[1]);
    }

    return trim((string) ieum_vehicle_api_value('driver_token', ''));
}

function ieum_vehicle_require_driver()
{
    $payload = ieum_vehicle_driver_token_payload(ieum_vehicle_request_token());
    if (!$payload) {
        ieum_json_response(false, '기사님 인증이 필요합니다. 다시 로그인해 주세요.', array(), 401);
    }

    $academy_id = (int) $payload['a'];
    $route_id = (int) $payload['r'];
    $row = sql_fetch("
        select a.academy_id, a.academy_code, a.academy_name, a.service_status, a.is_active,
               r.route_id, r.route_name, r.vehicle_label, r.driver_name, r.driver_phone
          from " . IEUM_ACADEMY_TABLE . " a
          join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.academy_id = a.academy_id
         where a.academy_id = '{$academy_id}'
           and r.route_id = '{$route_id}'
           and a.is_active = 1
           and a.service_status = 'active'
           and r.is_active = 1
         limit 1
    ", false);

    if (empty($row['academy_id'])) {
        ieum_json_response(false, '사용 가능한 차량 노선이 아닙니다.', array(), 403);
    }

    $row['expires_at'] = date('Y-m-d H:i:s', (int) $payload['e']);
    return $row;
}

function ieum_vehicle_api_ensure_tables()
{
    $engine = defined('G5_DB_ENGINE') && G5_DB_ENGINE ? G5_DB_ENGINE : 'InnoDB';
    $charset = defined('G5_DB_CHARSET') && G5_DB_CHARSET ? G5_DB_CHARSET : 'utf8';

    $driver_pin = sql_fetch("show columns from " . IEUM_VEHICLE_ROUTE_TABLE . " like 'driver_pin'", false);
    if (empty($driver_pin['Field'])) {
        sql_query("alter table " . IEUM_VEHICLE_ROUTE_TABLE . " add driver_pin varchar(20) not null default '' after driver_phone", false);
    }

    sql_query("
        create table if not exists " . IEUM_VEHICLE_RUN_TABLE . " (
            run_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            journal_date date not null,
            ride_type varchar(20) not null,
            route_id int unsigned not null default 0,
            vehicle_label varchar(50) not null default '',
            driver_member_id varchar(50) not null default '',
            status varchar(20) not null default 'active',
            started_at datetime not null,
            ended_at datetime null,
            last_lat decimal(10,7) null,
            last_lng decimal(10,7) null,
            last_location_at datetime null,
            created_at datetime not null,
            updated_at datetime null,
            primary key (run_id),
            key idx_active_run (academy_id, journal_date, ride_type, route_id, vehicle_label, status),
            key idx_driver_day (academy_id, journal_date, driver_member_id)
        ) engine={$engine} default charset={$charset}
    ", false);

    sql_query("
        create table if not exists " . IEUM_VEHICLE_LOCATION_TABLE . " (
            location_id int unsigned not null auto_increment,
            run_id int unsigned not null,
            academy_id int unsigned not null,
            journal_date date not null,
            ride_type varchar(20) not null,
            route_id int unsigned not null default 0,
            vehicle_label varchar(50) not null default '',
            lat decimal(10,7) not null,
            lng decimal(10,7) not null,
            accuracy decimal(10,2) null,
            recorded_at datetime not null,
            created_at datetime not null,
            primary key (location_id),
            key idx_run_time (run_id, recorded_at),
            key idx_academy_day (academy_id, journal_date, vehicle_label, ride_type)
        ) engine={$engine} default charset={$charset}
    ", false);
}

function ieum_vehicle_api_param_date($name, $default = '')
{
    $value = preg_replace('/[^0-9-]/', '', trim((string) ieum_vehicle_api_value($name, $default)));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return G5_TIME_YMD;
    }
    return $value;
}

function ieum_vehicle_api_param_ride_type()
{
    $ride_type = preg_replace('/[^a-z]/', '', trim((string) ieum_vehicle_api_value('ride_type', 'pickup')));
    if ($ride_type === 'all') {
        return 'all';
    }
    return $ride_type === 'dropoff' ? 'dropoff' : 'pickup';
}

function ieum_vehicle_api_weekday($date)
{
    $weekday_keys = array('sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat');
    return $weekday_keys[(int) date('w', strtotime($date))];
}

function ieum_vehicle_api_status_labels()
{
    return array(
        'unchecked' => '미확인',
        'boarded' => '탑승',
        'missed' => '미탑승',
        'called' => '보호자 통화',
        'self' => '개별 이동',
    );
}

function ieum_vehicle_api_grade_label($value)
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
        'adult' => '성인',
    );

    return isset($labels[$value]) ? $labels[$value] : $value;
}

function ieum_vehicle_api_photo_url($path)
{
    $path = trim((string) $path);
    return $path === '' ? '' : G5_URL . '/' . ltrim($path, '/');
}

function ieum_vehicle_api_active_run($academy_id, $journal_date, $ride_type, $route_id, $vehicle_label)
{
    return sql_fetch("
        select *
          from " . IEUM_VEHICLE_RUN_TABLE . "
         where academy_id = '" . (int) $academy_id . "'
           and journal_date = '" . sql_escape_string($journal_date) . "'
           and ride_type = '" . sql_escape_string($ride_type) . "'
           and route_id = '" . (int) $route_id . "'
           and vehicle_label = '" . sql_escape_string($vehicle_label) . "'
           and status = 'active'
      order by run_id desc
         limit 1
    ", false);
}

function ieum_vehicle_api_run_array($run)
{
    if (empty($run['run_id'])) {
        return null;
    }

    return array(
        'run_id' => (int) $run['run_id'],
        'status' => $run['status'],
        'started_at' => $run['started_at'],
        'ended_at' => isset($run['ended_at']) ? $run['ended_at'] : '',
        'last_lat' => $run['last_lat'] !== null ? (float) $run['last_lat'] : null,
        'last_lng' => $run['last_lng'] !== null ? (float) $run['last_lng'] : null,
        'last_location_at' => $run['last_location_at'],
    );
}

function ieum_vehicle_api_checked_by($driver)
{
    $name = trim((string) $driver['driver_name']);
    if ($name !== '') {
        return 'driver-app:' . $name;
    }
    return 'driver-app:route-' . (int) $driver['route_id'];
}

function ieum_vehicle_api_alert($academy_id, $vehicle, $status_label, $note)
{
    return ieum_create_vehicle_alert_sms_queue((int) $academy_id, $vehicle, $status_label, $note, G5_TIME_YMD);
}

function ieum_vehicle_api_roster($driver, $journal_date, $ride_type)
{
    $academy_id = (int) $driver['academy_id'];
    $route_id = (int) $driver['route_id'];
    $vehicle_label = sql_escape_string((string) $driver['vehicle_label']);
    $weekday = ieum_vehicle_api_weekday($journal_date);
    $labels = ieum_vehicle_api_status_labels();
    $route_filter = "sv.route_id = '{$route_id}' and sv.ride_type = '" . sql_escape_string($ride_type) . "'";
    if ($ride_type === 'all') {
        $route_filter = "r.vehicle_label = '{$vehicle_label}'";
    }

    $rows = sql_query("
        select sv.student_vehicle_id, sv.student_id, sv.ride_type, sv.place_name, sv.contact_phone, sv.memo as vehicle_memo,
               s.student_code, s.student_name, s.grade_group, s.student_photo, s.memo as student_memo,
               c.class_name, c.start_time as class_start_time,
               r.route_id, r.route_name, r.vehicle_label,
               st.stop_id, st.stop_type, st.stop_name, st.stop_address, st.map_url, st.map_lat, st.map_lng, st.stop_time,
               bl.status as boarding_status, bl.note as boarding_note, bl.checked_at, bl.resolved_at
          from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
          join " . IEUM_STUDENT_TABLE . " s on s.student_id = sv.student_id and s.academy_id = sv.academy_id
     left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
          join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = sv.route_id and r.academy_id = sv.academy_id
          join " . IEUM_VEHICLE_STOP_TABLE . " st on st.stop_id = sv.stop_id and st.academy_id = sv.academy_id
     left join " . IEUM_VEHICLE_BOARDING_TABLE . " bl on bl.academy_id = sv.academy_id and bl.student_vehicle_id = sv.student_vehicle_id and bl.journal_date = '" . sql_escape_string($journal_date) . "'
         where sv.academy_id = '{$academy_id}'
           and {$route_filter}
           and sv.is_active = 1
           and s.is_active = 1
           and st.is_active = 1
           and r.is_active = 1
           and (sv.ride_days = '' or find_in_set('" . sql_escape_string($weekday) . "', sv.ride_days))
      order by st.stop_time asc, st.sort_order asc, st.stop_name asc, sv.ride_type asc, s.student_name asc
    ", false);

    $summary = array('total' => 0, 'unchecked' => 0, 'boarded' => 0, 'missed' => 0, 'called' => 0, 'self' => 0, 'resolved' => 0);
    $stops = array();
    $stop_index = array();
    while ($row = sql_fetch_array($rows)) {
        $summary['total']++;
        $status = isset($row['boarding_status']) && $row['boarding_status'] !== '' ? $row['boarding_status'] : 'unchecked';
        if (isset($summary[$status])) {
            $summary[$status]++;
        } else {
            $summary['unchecked']++;
            $status = 'unchecked';
        }
        if (!empty($row['resolved_at'])) {
            $summary['resolved']++;
        }

        $stop_key = (int) $row['stop_id'];
        if (!isset($stop_index[$stop_key])) {
            $stop_index[$stop_key] = count($stops);
            $stops[] = array(
                'stop_id' => (int) $row['stop_id'],
                'ride_type' => $row['ride_type'],
                'stop_type' => $row['stop_type'],
                'route_id' => (int) $row['route_id'],
                'route_name' => $row['route_name'],
                'vehicle_label' => $row['vehicle_label'],
                'stop_name' => $row['stop_name'],
                'stop_time' => $row['stop_time'],
                'stop_address' => $row['stop_address'],
                'map_url' => $row['map_url'],
                'map_lat' => $row['map_lat'] !== null ? (float) $row['map_lat'] : null,
                'map_lng' => $row['map_lng'] !== null ? (float) $row['map_lng'] : null,
                'students' => array(),
            );
        }

        $memo = trim(($row['vehicle_memo'] ?: $row['place_name']) . ($row['student_memo'] ? ' / ' . $row['student_memo'] : ''));
        $stops[$stop_index[$stop_key]]['students'][] = array(
            'student_vehicle_id' => (int) $row['student_vehicle_id'],
            'student_id' => (int) $row['student_id'],
            'ride_type' => $row['ride_type'],
            'student_code' => $row['student_code'],
            'student_name' => $row['student_name'],
            'grade_group' => $row['grade_group'],
            'grade_label' => ieum_vehicle_api_grade_label($row['grade_group']),
            'class_name' => $row['class_name'],
            'class_start_time' => $row['class_start_time'],
            'contact_phone' => $row['contact_phone'],
            'photo_url' => ieum_vehicle_api_photo_url($row['student_photo']),
            'memo' => $memo,
            'status' => $status,
            'status_label' => isset($labels[$status]) ? $labels[$status] : $status,
            'boarding_note' => isset($row['boarding_note']) ? $row['boarding_note'] : '',
            'checked_at' => isset($row['checked_at']) ? $row['checked_at'] : '',
            'resolved_at' => isset($row['resolved_at']) ? $row['resolved_at'] : '',
        );
    }

    return array($summary, $stops);
}

function ieum_vehicle_api_save_boarding($driver, $journal_date, $student_vehicle_id, $status, $note)
{
    $labels = ieum_vehicle_api_status_labels();
    if (!isset($labels[$status]) || $status === 'unchecked') {
        ieum_json_response(false, '탑승 상태를 선택해 주세요.', array(), 400);
    }

    $academy_id = (int) $driver['academy_id'];
    $route_id = (int) $driver['route_id'];
    $vehicle_label_sql = sql_escape_string((string) $driver['vehicle_label']);
    $student_vehicle_id = (int) $student_vehicle_id;
    $vehicle = sql_fetch("
        select sv.*, s.student_name, r.route_name, r.vehicle_label
          from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
          join " . IEUM_STUDENT_TABLE . " s on s.student_id = sv.student_id and s.academy_id = sv.academy_id
     left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = sv.route_id and r.academy_id = sv.academy_id
         where sv.academy_id = '{$academy_id}'
           and (sv.route_id = '{$route_id}' or r.vehicle_label = '{$vehicle_label_sql}')
           and sv.student_vehicle_id = '{$student_vehicle_id}'
           and sv.is_active = 1
           and r.is_active = 1
         limit 1
    ", false);

    if (empty($vehicle['student_vehicle_id'])) {
        ieum_json_response(false, '차량 배정 정보를 찾을 수 없습니다.', array(), 404);
    }

    $before = sql_fetch("
        select status, note
          from " . IEUM_VEHICLE_BOARDING_TABLE . "
         where academy_id = '{$academy_id}'
           and journal_date = '" . sql_escape_string($journal_date) . "'
           and student_vehicle_id = '{$student_vehicle_id}'
         limit 1
    ", false);

    $status_sql = sql_escape_string($status);
    $note_sql = sql_escape_string(trim((string) $note));
    $checked_by_sql = sql_escape_string(ieum_vehicle_api_checked_by($driver));
    $date_sql = sql_escape_string($journal_date);
    $ride_type_sql = sql_escape_string($vehicle['ride_type']);

    sql_query("
        insert into " . IEUM_VEHICLE_BOARDING_TABLE . "
            set academy_id = '{$academy_id}',
                journal_date = '{$date_sql}',
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

    $changed = empty($before['status']) || $before['status'] !== $status || (string) $before['note'] !== trim((string) $note);
    $needs_alert = ($status === 'missed' || $status === 'called' || trim((string) $note) !== '');
    if ($changed && $needs_alert) {
        ieum_vehicle_api_alert($academy_id, $vehicle, $labels[$status], $note);
    } elseif ($changed && !$needs_alert && !empty($before['status']) && ($before['status'] === 'missed' || $before['status'] === 'called' || trim((string) $before['note']) !== '')) {
        ieum_cancel_pending_vehicle_alert_sms_queue($academy_id, $vehicle, $journal_date);
    }

    return array(
        'student_vehicle_id' => $student_vehicle_id,
        'student_id' => (int) $vehicle['student_id'],
        'student_name' => $vehicle['student_name'],
        'status' => $status,
        'status_label' => $labels[$status],
        'note' => trim((string) $note),
        'checked_at' => G5_TIME_YMDHIS,
    );
}

function ieum_vehicle_api_bulk_boarding($driver, $journal_date, $ride_type)
{
    $academy_id = (int) $driver['academy_id'];
    $route_id = (int) $driver['route_id'];
    $vehicle_label_sql = sql_escape_string((string) $driver['vehicle_label']);
    $weekday = ieum_vehicle_api_weekday($journal_date);
    $checked_by_sql = sql_escape_string(ieum_vehicle_api_checked_by($driver));
    $route_filter = "sv.route_id = '{$route_id}' and sv.ride_type = '" . sql_escape_string($ride_type) . "'";
    if ($ride_type === 'all') {
        $route_filter = "r.vehicle_label = '{$vehicle_label_sql}'";
    }

    $rows = sql_query("
        select sv.student_vehicle_id, sv.student_id, sv.ride_type, sv.route_id, sv.stop_id
          from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
          join " . IEUM_STUDENT_TABLE . " s on s.student_id = sv.student_id and s.academy_id = sv.academy_id
          join " . IEUM_VEHICLE_STOP_TABLE . " st on st.stop_id = sv.stop_id and st.academy_id = sv.academy_id
          join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = sv.route_id and r.academy_id = sv.academy_id
     left join " . IEUM_VEHICLE_BOARDING_TABLE . " bl on bl.academy_id = sv.academy_id and bl.student_vehicle_id = sv.student_vehicle_id and bl.journal_date = '" . sql_escape_string($journal_date) . "'
         where sv.academy_id = '{$academy_id}'
           and {$route_filter}
           and sv.is_active = 1
           and s.is_active = 1
           and st.is_active = 1
           and r.is_active = 1
           and (sv.ride_days = '' or find_in_set('" . sql_escape_string($weekday) . "', sv.ride_days))
           and bl.log_id is null
    ", false);

    $count = 0;
    while ($row = sql_fetch_array($rows)) {
        sql_query("
            insert into " . IEUM_VEHICLE_BOARDING_TABLE . "
                set academy_id = '{$academy_id}',
                    journal_date = '" . sql_escape_string($journal_date) . "',
                    student_vehicle_id = '" . (int) $row['student_vehicle_id'] . "',
                    student_id = '" . (int) $row['student_id'] . "',
                    ride_type = '" . sql_escape_string($row['ride_type']) . "',
                    route_id = '" . (int) $row['route_id'] . "',
                    stop_id = '" . (int) $row['stop_id'] . "',
                    status = 'boarded',
                    note = '',
                    checked_by = '{$checked_by_sql}',
                    checked_at = '" . G5_TIME_YMDHIS . "',
                    resolved_by = '',
                    resolved_at = null,
                    created_at = '" . G5_TIME_YMDHIS . "'
            on duplicate key update
                    status = 'boarded',
                    note = '',
                    checked_by = '{$checked_by_sql}',
                    checked_at = '" . G5_TIME_YMDHIS . "',
                    resolved_by = '',
                    resolved_at = null,
                    updated_at = '" . G5_TIME_YMDHIS . "'
        ");
        $count++;
    }

    return $count;
}
