<?php
$sub_menu = '950121';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/simplexlsx/SimpleXLS.php';
require_once IEUM_PATH . '/lib/simplexlsx/SimpleXLSX.php';

$g5['title'] = '아이이음 원생 엑셀 가져오기';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';
$import_result = null;
$preview_result = null;
$preview_token = '';

function ieum_import_headers()
{
    return array(
        '학생번호',
        '학생명',
        '생년월일',
        '학생연락처',
        '프로그램',
        '학교명',
        '학년',
        '수업부',
        '출석요일',
        '입관일',
        '수련비',
        '납부일',
        '보호자1명',
        '보호자1연락처',
        '보호자1관계',
        '보호자2명',
        '보호자2연락처',
        '보호자2관계',
        '메모',
        '차량이용여부',
        '차량코스',
        '형제자매',
    );
}

function ieum_import_send_csv($filename, $headers, $rows)
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

function ieum_import_clean_phone($value)
{
    $digits = preg_replace('/[^0-9]/', '', (string) $value);
    if (strlen($digits) === 11) {
        return substr($digits, 0, 3) . '-' . substr($digits, 3, 4) . '-' . substr($digits, 7);
    }
    if (strlen($digits) === 10) {
        return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6);
    }
    return $digits;
}

function ieum_import_digits($value)
{
    return preg_replace('/[^0-9]/', '', (string) $value);
}

function ieum_import_date($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (is_numeric($value)) {
        $serial = (int) $value;
        if ($serial > 20000 && $serial < 80000) {
            return gmdate('Y-m-d', ($serial - 25569) * 86400);
        }
    }
    $value = str_replace(array('.', '/', '년', '월'), '-', $value);
    $value = str_replace('일', '', $value);
    $value = preg_replace('/\s+/', '', $value);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    return null;
}

function ieum_import_grade_code($value)
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/u', '', $value);
    $map = array(
        '유치부' => 'kindergarten',
        '유' => 'kindergarten',
        '초-1' => 'elementary_1',
        '초1' => 'elementary_1',
        '초등1' => 'elementary_1',
        '초등1학년' => 'elementary_1',
        '초-2' => 'elementary_2',
        '초2' => 'elementary_2',
        '초등2' => 'elementary_2',
        '초등2학년' => 'elementary_2',
        '초-3' => 'elementary_3',
        '초3' => 'elementary_3',
        '초등3' => 'elementary_3',
        '초등3학년' => 'elementary_3',
        '초-4' => 'elementary_4',
        '초4' => 'elementary_4',
        '초등4' => 'elementary_4',
        '초등4학년' => 'elementary_4',
        '초-5' => 'elementary_5',
        '초5' => 'elementary_5',
        '초등5' => 'elementary_5',
        '초등5학년' => 'elementary_5',
        '초-6' => 'elementary_6',
        '초6' => 'elementary_6',
        '초등6' => 'elementary_6',
        '초등6학년' => 'elementary_6',
        '중-1' => 'middle_1',
        '중1' => 'middle_1',
        '중등1' => 'middle_1',
        '중-2' => 'middle_2',
        '중2' => 'middle_2',
        '중등2' => 'middle_2',
        '중-3' => 'middle_3',
        '중3' => 'middle_3',
        '중등3' => 'middle_3',
        '고-1' => 'high_1',
        '고1' => 'high_1',
        '고등1' => 'high_1',
        '고-2' => 'high_2',
        '고2' => 'high_2',
        '고등2' => 'high_2',
        '고-3' => 'high_3',
        '고3' => 'high_3',
        '고등3' => 'high_3',
        '성인' => 'adult',
    );
    if (isset($map[$value])) {
        return $map[$value];
    }

    if (preg_match('/^(초|초등|초등부|초등학교)[\-\/]?([1-6])(?:학년)?$/u', $value, $m)) {
        return 'elementary_' . (int) $m[2];
    }
    if (preg_match('/^(중|중등|중등부|중학교)[\-\/]?([1-3])(?:학년)?$/u', $value, $m)) {
        return 'middle_' . (int) $m[2];
    }
    if (preg_match('/^(고|고등|고등부|고등학교)[\-\/]?([1-3])(?:학년)?$/u', $value, $m)) {
        return 'high_' . (int) $m[2];
    }

    return '';
}

function ieum_import_grade_label($value)
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
        'adult' => '성인',
    );
    return isset($labels[$value]) ? $labels[$value] : $value;
}

function ieum_import_attendance_days($value)
{
    $value = trim((string) $value);
    $map = array('월' => 'mon', '화' => 'tue', '수' => 'wed', '목' => 'thu', '금' => 'fri', '토' => 'sat', '일' => 'sun');
    $days = array();
    foreach ($map as $ko => $code) {
        if (strpos($value, $ko) !== false) {
            $days[$code] = $code;
        }
    }
    if (!$days) {
        return 'mon,tue,wed,thu,fri';
    }
    return implode(',', array_values($days));
}

function ieum_import_attendance_days_label($days_csv)
{
    $labels = array(
        'mon' => '월',
        'tue' => '화',
        'wed' => '수',
        'thu' => '목',
        'fri' => '금',
        'sat' => '토',
        'sun' => '일',
    );
    $result = array();
    foreach (array_filter(explode(',', (string) $days_csv)) as $day) {
        $day = trim($day);
        if (isset($labels[$day])) {
            $result[] = $labels[$day];
        }
    }
    return $result ? implode(' ', $result) : '';
}

function ieum_import_week_type($days_csv)
{
    $count = count(array_filter(explode(',', (string) $days_csv)));
    if ($count <= 0) {
        return '5';
    }
    return (string) min(5, $count);
}

function ieum_import_status_label($status)
{
    $labels = array(
        'insert' => '신규 등록',
        'update' => '기존 수정',
        'skip' => '건너뜀',
    );
    return isset($labels[$status]) ? $labels[$status] : $status;
}

function ieum_import_warning_meta($warning)
{
    $map = array(
        '원생명 또는 원생번호 없음' => array('order' => 1, 'title' => '등록 불가', 'action' => '원생명과 원생번호를 먼저 확인하세요.'),
        '파일 안 원생번호 중복' => array('order' => 2, 'title' => '번호 중복', 'action' => '같은 번호 원생은 생년월일이나 보호자 연락처로 구분하세요.'),
        '보호자 연락처 없음' => array('order' => 3, 'title' => '문자 불가', 'action' => '등하원 문자와 수련비 안내를 위해 보호자 연락처를 채우세요.'),
        '프로그램 미매칭' => array('order' => 4, 'title' => '프로그램 연결', 'action' => '태권도, 합기도, 줄넘기 등 아이이음 프로그램과 연결하세요.'),
        '수업부 미매칭' => array('order' => 5, 'title' => '수업부 연결', 'action' => '1부, 2부 같은 수업부를 선택하면 출석/차량/리포트 분류가 편해집니다.'),
        '학년 미분류' => array('order' => 6, 'title' => '학년 확인', 'action' => '초등/중등/고등 학년을 맞추면 부별 명단과 리포트가 정리됩니다.'),
        '생년월일 없음' => array('order' => 7, 'title' => '생년월일 확인', 'action' => '생일, 자동 학년 변경, 중복 번호 구분에 사용됩니다.'),
    );
    if (isset($map[$warning])) {
        return $map[$warning];
    }
    return array('order' => 99, 'title' => '기타 확인', 'action' => '원본 엑셀의 값을 확인하세요.');
}

function ieum_import_warning_order($warnings)
{
    $order = 99;
    foreach ((array) $warnings as $warning) {
        $meta = ieum_import_warning_meta($warning);
        $order = min($order, (int) $meta['order']);
    }
    return $order;
}

function ieum_import_normalize_header($value)
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', (string) $value);
    $value = trim($value);
    return preg_replace('/[\s\*\(\)\[\]\/._-]+/u', '', mb_strtolower($value, 'UTF-8'));
}

function ieum_import_value($row, $aliases, $default = '')
{
    foreach ((array) $aliases as $alias) {
        $key = ieum_import_normalize_header($alias);
        if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
            return trim((string) $row[$key]);
        }
    }
    return $default;
}

function ieum_import_default_program($academy_id)
{
    $programs = ieum_program_options($academy_id);
    foreach ($programs as $program) {
        if (!empty($program['is_default'])) {
            return $program['program_code'];
        }
    }
    return isset($programs[0]['program_code']) ? $programs[0]['program_code'] : 'taekwondo';
}

function ieum_import_program_code($academy_id, $value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return ieum_import_default_program($academy_id);
    }
    $programs = ieum_program_options($academy_id);
    foreach ($programs as $program) {
        if ($value === $program['program_code'] || $value === $program['program_name']) {
            return $program['program_code'];
        }
    }
    return ieum_import_default_program($academy_id);
}

function ieum_import_program_match_code($academy_id, $value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $programs = ieum_program_options($academy_id);
    foreach ($programs as $program) {
        if ($value === $program['program_code'] || $value === $program['program_name']) {
            return $program['program_code'];
        }
    }
    return '';
}

function ieum_import_program_map_key($program_text)
{
    return md5(trim((string) $program_text));
}

function ieum_import_program_label_from_options($program_options, $program_code)
{
    $program_code = trim((string) $program_code);
    foreach ((array) $program_options as $program_option) {
        if ($program_option['program_code'] === $program_code) {
            return $program_option['program_name'];
        }
    }
    return '';
}

function ieum_import_class_time_id($academy_id, $value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $academy_id = (int) $academy_id;
    $value_sql = sql_escape_string($value);
    $row = sql_fetch("
        select class_time_id
          from " . IEUM_CLASS_TIME_TABLE . "
         where academy_id = '{$academy_id}'
           and (class_name = '{$value_sql}' or concat(class_name, ' ', start_time) = '{$value_sql}')
         limit 1
    ", false);
    return isset($row['class_time_id']) ? (int) $row['class_time_id'] : 0;
}

function ieum_import_class_options($academy_id)
{
    $academy_id = (int) $academy_id;
    $rows = array();
    $result = sql_query("
        select class_time_id, class_name, start_time
          from " . IEUM_CLASS_TIME_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
      order by sort_order asc, start_time asc, class_time_id asc
    ", false);
    while ($row = sql_fetch_array($result)) {
        $rows[] = array(
            'class_time_id' => (int) $row['class_time_id'],
            'label' => trim($row['class_name'] . ' ' . $row['start_time']),
        );
    }
    return $rows;
}

function ieum_import_class_label_from_options($class_options, $class_time_id)
{
    $class_time_id = (int) $class_time_id;
    foreach ((array) $class_options as $class_option) {
        if ((int) $class_option['class_time_id'] === $class_time_id) {
            return $class_option['label'];
        }
    }
    return '';
}

function ieum_import_class_map_key($class_text)
{
    return md5(trim((string) $class_text));
}

function ieum_import_ensure_mapping_table()
{
    sql_query("
        create table if not exists " . IEUM_IMPORT_MAPPING_TABLE . " (
            mapping_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            mapping_type varchar(30) not null,
            source_hash char(32) not null,
            source_value varchar(190) not null default '',
            target_value varchar(190) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            primary key (mapping_id),
            unique key uq_academy_import_mapping (academy_id, mapping_type, source_hash),
            key idx_academy_mapping_type (academy_id, mapping_type)
        ) engine=InnoDB default charset=utf8
    ", false);
}

function ieum_import_mapping_hash($source_value)
{
    return md5(trim((string) $source_value));
}

function ieum_import_saved_mapping($academy_id, $mapping_type, $source_value)
{
    $source_value = trim((string) $source_value);
    if ((int) $academy_id <= 0 || $source_value === '') {
        return '';
    }

    ieum_import_ensure_mapping_table();
    $academy_id = (int) $academy_id;
    $mapping_type_sql = sql_escape_string($mapping_type);
    $source_hash_sql = sql_escape_string(ieum_import_mapping_hash($source_value));
    $row = sql_fetch("
        select target_value
          from " . IEUM_IMPORT_MAPPING_TABLE . "
         where academy_id = '{$academy_id}'
           and mapping_type = '{$mapping_type_sql}'
           and source_hash = '{$source_hash_sql}'
         limit 1
    ", false);

    return isset($row['target_value']) ? trim((string) $row['target_value']) : '';
}

function ieum_import_save_mapping($academy_id, $mapping_type, $source_value, $target_value)
{
    $source_value = trim((string) $source_value);
    $target_value = trim((string) $target_value);
    if ((int) $academy_id <= 0 || $source_value === '' || $target_value === '') {
        return;
    }

    ieum_import_ensure_mapping_table();
    $academy_id = (int) $academy_id;
    $mapping_type_sql = sql_escape_string($mapping_type);
    $source_hash_sql = sql_escape_string(ieum_import_mapping_hash($source_value));
    $source_value_sql = sql_escape_string(mb_substr($source_value, 0, 190, 'UTF-8'));
    $target_value_sql = sql_escape_string(mb_substr($target_value, 0, 190, 'UTF-8'));

    sql_query("
        insert into " . IEUM_IMPORT_MAPPING_TABLE . "
            set academy_id = '{$academy_id}',
                mapping_type = '{$mapping_type_sql}',
                source_hash = '{$source_hash_sql}',
                source_value = '{$source_value_sql}',
                target_value = '{$target_value_sql}',
                created_at = '" . G5_TIME_YMDHIS . "',
                updated_at = null
        on duplicate key update
                source_value = values(source_value),
                target_value = values(target_value),
                updated_at = '" . G5_TIME_YMDHIS . "'
    ");
}

function ieum_import_ensure_log_table()
{
    sql_query("
        create table if not exists " . IEUM_IMPORT_LOG_TABLE . " (
            import_log_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            filename varchar(190) not null default '',
            insert_count int unsigned not null default 0,
            update_count int unsigned not null default 0,
            skip_count int unsigned not null default 0,
            warning_count int unsigned not null default 0,
            created_by varchar(50) not null default '',
            created_at datetime not null,
            primary key (import_log_id),
            key idx_academy_created (academy_id, created_at)
        ) engine=InnoDB default charset=utf8
    ", false);
}

function ieum_import_save_log($academy_id, $filename, $result, $member_id)
{
    ieum_import_ensure_log_table();
    $academy_id = (int) $academy_id;
    $filename_sql = sql_escape_string(mb_substr((string) $filename, 0, 190, 'UTF-8'));
    $insert_count = isset($result['insert']) ? (int) $result['insert'] : 0;
    $update_count = isset($result['update']) ? (int) $result['update'] : 0;
    $skip_count = isset($result['skip']) ? (int) $result['skip'] : 0;
    $warning_count = isset($result['warning']) ? (int) $result['warning'] : 0;
    $member_id_sql = sql_escape_string(mb_substr((string) $member_id, 0, 50, 'UTF-8'));

    sql_query("
        insert into " . IEUM_IMPORT_LOG_TABLE . "
            set academy_id = '{$academy_id}',
                filename = '{$filename_sql}',
                insert_count = '{$insert_count}',
                update_count = '{$update_count}',
                skip_count = '{$skip_count}',
                warning_count = '{$warning_count}',
                created_by = '{$member_id_sql}',
                created_at = '" . G5_TIME_YMDHIS . "'
    ");
}

function ieum_import_recent_logs($academy_id)
{
    ieum_import_ensure_log_table();
    $academy_id = (int) $academy_id;
    $rows = array();
    $result = sql_query("
        select *
          from " . IEUM_IMPORT_LOG_TABLE . "
         where academy_id = '{$academy_id}'
      order by created_at desc, import_log_id desc
         limit 5
    ", false);
    while ($row = sql_fetch_array($result)) {
        $rows[] = $row;
    }
    return $rows;
}

function ieum_import_csv_rows($tmp_name)
{
    $raw = file_get_contents($tmp_name);
    if ($raw === false || $raw === '') {
        return array();
    }
    if (substr($raw, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
        return ieum_import_spreadsheet_rows($tmp_name, 'xls');
    }
    if (substr($raw, 0, 2) === "PK") {
        return ieum_import_spreadsheet_rows($tmp_name, 'xlsx');
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'CP949,EUC-KR,SJIS,UTF-8');
    }
    $first_line = strtok($raw, "\r\n");
    $delimiter = substr_count((string) $first_line, "\t") > substr_count((string) $first_line, ',') ? "\t" : ',';
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $raw);
    rewind($fp);
    $headers = fgetcsv($fp, 0, $delimiter);
    if (!$headers) {
        fclose($fp);
        return array();
    }
    $keys = array();
    foreach ($headers as $header) {
        $keys[] = ieum_import_normalize_header($header);
    }
    $rows = array();
    while (($values = fgetcsv($fp, 0, $delimiter)) !== false) {
        if (!$values || !array_filter($values, function ($v) { return trim((string) $v) !== ''; })) {
            continue;
        }
        $row = array();
        foreach ($keys as $idx => $key) {
            if ($key === '') {
                continue;
            }
            $row[$key] = isset($values[$idx]) ? $values[$idx] : '';
        }
        $rows[] = $row;
    }
    fclose($fp);
    return $rows;
}

function ieum_import_spreadsheet_rows($tmp_name, $type)
{
    if ($type === 'xlsx') {
        $sheet = \Shuchkin\SimpleXLSX::parse($tmp_name);
        if (!$sheet) {
            throw new RuntimeException('엑셀 파일을 읽지 못했습니다. 파일이 열려 있거나 암호가 걸려 있는지 확인해 주세요.');
        }
        return ieum_import_table_rows_to_assoc($sheet->rows());
    }

    $sheet = \Shuchkin\SimpleXLS::parse($tmp_name);
    if (!$sheet) {
        throw new RuntimeException('엑셀 파일을 읽지 못했습니다. 파일이 열려 있거나 암호가 걸려 있는지 확인해 주세요.');
    }
    return ieum_import_table_rows_to_assoc($sheet->rows());
}

function ieum_import_table_rows_to_assoc($table_rows)
{
    $headers = array();
    $rows = array();
    foreach ((array) $table_rows as $line) {
        $line = array_map(function ($value) {
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d');
            }
            return trim((string) $value);
        }, (array) $line);

        if (!array_filter($line, function ($value) { return trim((string) $value) !== ''; })) {
            continue;
        }

        if (!$headers) {
            foreach ($line as $header) {
                $headers[] = ieum_import_normalize_header($header);
            }
            continue;
        }

        $row = array();
        foreach ($headers as $idx => $key) {
            if ($key === '') {
                continue;
            }
            $row[$key] = isset($line[$idx]) ? $line[$idx] : '';
        }
        $rows[] = $row;
    }
    return $rows;
}

function ieum_import_guardian_values($row, $idx)
{
    $name = ieum_import_value($row, array("보호자{$idx}명", "부모{$idx}이름", "부모{$idx} 이름"));
    $phone = ieum_import_clean_phone(ieum_import_value($row, array("보호자{$idx}연락처", "보호자{$idx}전화번호", "부모{$idx}전화번호", "부모{$idx} 전화번호")));
    $relation = ieum_import_value($row, array("보호자{$idx}관계", "부모{$idx}관계", "부모{$idx} 관계"), '보호자');
    if ($name === '' && $phone === '') {
        return null;
    }
    return array('name' => $name, 'phone' => $phone, 'relation' => $relation);
}

function ieum_import_row_payload($academy_id, $row)
{
    $student_name = ieum_import_value($row, array('학생명', '원생이름', '이름'));
    $student_code = preg_replace('/[^0-9]/', '', ieum_import_value($row, array('학생번호', '원생번호')));
    $guardian1 = ieum_import_guardian_values($row, 1);
    $guardian2 = ieum_import_guardian_values($row, 2);
    $student_phone = ieum_import_clean_phone(ieum_import_value($row, array('학생연락처', '학생휴대폰', '휴대폰번호', '전화번호')));
    if ($student_code === '') {
        $basis = $guardian1 && $guardian1['phone'] !== '' ? $guardian1['phone'] : $student_phone;
        $digits = ieum_import_digits($basis);
        $student_code = strlen($digits) >= 4 ? substr($digits, -4) : '';
    }

    $birth_date = ieum_import_date(ieum_import_value($row, array('생년월일', '생일')));
    $program_text = ieum_import_value($row, array('프로그램', '종목'));
    $program_code = isset($row['__program_code']) ? ieum_program_code($row['__program_code']) : '';
    $program_mapping_source = '';
    if ($program_code === '') {
        $program_match_code = ieum_import_program_match_code($academy_id, $program_text);
        if ($program_match_code !== '') {
            $program_code = $program_match_code;
            $program_mapping_source = 'direct';
        } else {
            $saved_program_code = ieum_program_code(ieum_import_saved_mapping($academy_id, 'program', $program_text));
            if ($saved_program_code !== '' && ieum_import_program_match_code($academy_id, $saved_program_code) !== '') {
                $program_code = $saved_program_code;
                $program_match_code = $saved_program_code;
                $program_mapping_source = 'saved';
            } else {
                $program_code = ieum_import_default_program($academy_id);
                $program_mapping_source = 'default';
            }
        }
    } else {
        $program_match_code = $program_code;
        $program_mapping_source = 'manual';
    }
    $school_name = ieum_import_value($row, array('학교명', '학교'));
    $grade_group = ieum_import_grade_code(ieum_import_value($row, array('학년', '학년부')));
    $class_text = ieum_import_value($row, array('수업부', '부', '반'));
    $class_time_id = isset($row['__class_time_id']) ? (int) $row['__class_time_id'] : 0;
    if ($class_time_id <= 0) {
        $class_time_id = ieum_import_class_time_id($academy_id, $class_text);
        if ($class_time_id <= 0) {
            $saved_class_time_id = (int) ieum_import_saved_mapping($academy_id, 'class', $class_text);
            if ($saved_class_time_id > 0) {
                $class_time_id = $saved_class_time_id;
            }
        }
    }
    $attendance_days = ieum_import_attendance_days(ieum_import_value($row, array('출석요일', '출석일')));
    $attendance_week_type = ieum_import_week_type($attendance_days);
    $admission_date = ieum_import_date(ieum_import_value($row, array('입관일', '가입일')));
    $tuition_amount = (int) preg_replace('/[^0-9]/', '', ieum_import_value($row, array('수련비', '기본수강료'), '0'));
    $tuition_due_day = (int) preg_replace('/[^0-9]/', '', ieum_import_value($row, array('납부일', '수납일'), '5'));
    if ($tuition_due_day < 1 || $tuition_due_day > 31) {
        $tuition_due_day = 5;
    }
    $memo_parts = array();
    foreach (array('성별', '주소', '반', '강사명', '특이사항', '퇴원일', '현금영수증 사용', '현금영수증 번호', '차량이용여부', '차량코스', '형제자매') as $field) {
        $value = ieum_import_value($row, array($field));
        if ($value !== '') {
            $memo_parts[] = $field . ': ' . $value;
        }
    }
    $memo = trim(ieum_import_value($row, array('메모')) . ($memo_parts ? "\n[이전 업체 정보]\n" . implode("\n", $memo_parts) : ''));

    return array(
        'student_name' => $student_name,
        'student_code' => $student_code,
        'guardian1' => $guardian1,
        'guardian2' => $guardian2,
        'student_phone' => $student_phone,
        'birth_date' => $birth_date,
        'program_text' => $program_text,
        'program_code' => $program_code,
        'program_matched' => ($program_text === '' || $program_match_code !== ''),
        'program_mapping_source' => $program_mapping_source,
        'school_name' => $school_name,
        'grade_group' => $grade_group,
        'class_text' => $class_text,
        'class_time_id' => $class_time_id,
        'attendance_days' => $attendance_days,
        'attendance_week_type' => $attendance_week_type,
        'admission_date' => $admission_date,
        'tuition_amount' => $tuition_amount,
        'tuition_due_day' => $tuition_due_day,
        'memo' => $memo,
    );
}

function ieum_import_existing_student_id($academy_id, $student_code)
{
    $academy_id = (int) $academy_id;
    $student_code = trim((string) $student_code);
    if ($academy_id <= 0 || $student_code === '') {
        return 0;
    }
    $code_sql = sql_escape_string($student_code);
    $existing = sql_fetch("
        select student_id
          from " . IEUM_STUDENT_TABLE . "
         where academy_id = '{$academy_id}'
           and student_code = '{$code_sql}'
      order by is_active desc, student_id desc
         limit 1
    ", false);
    return !empty($existing['student_id']) ? (int) $existing['student_id'] : 0;
}

function ieum_import_preview_item($academy_id, $row, $update_existing)
{
    $payload = ieum_import_row_payload($academy_id, $row);
    $program_options = ieum_program_options($academy_id);
    $class_options = ieum_import_class_options($academy_id);
    $warnings = array();
    if ($payload['student_name'] === '' || $payload['student_code'] === '') {
        return array(
            'status' => 'skip',
            'message' => '원생명 또는 원생번호 없음',
            'student_name' => $payload['student_name'],
            'student_code' => $payload['student_code'],
            'program_text' => $payload['program_text'],
            'program_code' => $payload['program_code'],
            'program_label' => '',
            'program_key' => ieum_import_program_map_key($payload['program_text']),
            'program_matched' => $payload['program_matched'],
            'school_name' => $payload['school_name'],
            'grade_label' => ieum_import_grade_label($payload['grade_group']),
            'class_text' => $payload['class_text'],
            'class_time_id' => $payload['class_time_id'],
            'class_label' => '',
            'class_key' => ieum_import_class_map_key($payload['class_text']),
            'attendance_days' => $payload['attendance_days'],
            'attendance_days_label' => ieum_import_attendance_days_label($payload['attendance_days']),
            'guardian_phone' => $payload['guardian1'] ? $payload['guardian1']['phone'] : '',
            'warnings' => array('원생명 또는 원생번호 없음'),
        );
    }
    $existing_id = ieum_import_existing_student_id($academy_id, $payload['student_code']);
    $status = $existing_id ? ($update_existing ? 'update' : 'skip') : 'insert';
    $message = $existing_id ? ($update_existing ? '기존 원생 업데이트 예정' : '같은 원생번호가 있어 건너뜀') : '신규 등록 예정';
    if ($payload['birth_date'] === '') {
        $warnings[] = '생년월일 없음';
    }
    if (!$payload['program_matched']) {
        $warnings[] = '프로그램 미매칭';
    }
    if ($payload['grade_group'] === '') {
        $warnings[] = '학년 미분류';
    }
    if ($payload['class_text'] !== '' && !$payload['class_time_id']) {
        $warnings[] = '수업부 미매칭';
    }
    if (!$payload['guardian1'] || $payload['guardian1']['phone'] === '') {
        $warnings[] = '보호자 연락처 없음';
    }
    return array(
        'status' => $status,
        'message' => $message,
        'student_name' => $payload['student_name'],
        'student_code' => $payload['student_code'],
        'program_text' => $payload['program_text'],
        'program_code' => $payload['program_code'],
        'program_label' => ieum_import_program_label_from_options($program_options, $payload['program_code']),
        'program_key' => ieum_import_program_map_key($payload['program_text']),
        'program_matched' => $payload['program_matched'],
        'school_name' => $payload['school_name'],
        'grade_label' => ieum_import_grade_label($payload['grade_group']),
        'class_text' => $payload['class_text'],
        'class_time_id' => $payload['class_time_id'],
        'class_label' => ieum_import_class_label_from_options($class_options, $payload['class_time_id']),
        'class_key' => ieum_import_class_map_key($payload['class_text']),
        'attendance_days' => $payload['attendance_days'],
        'attendance_days_label' => ieum_import_attendance_days_label($payload['attendance_days']),
        'guardian_phone' => $payload['guardian1'] ? $payload['guardian1']['phone'] : '',
        'warnings' => $warnings,
    );
}

function ieum_import_preview_result($academy_id, $rows, $update_existing)
{
    $result = array('insert' => 0, 'update' => 0, 'skip' => 0, 'warning' => 0, 'duplicate' => 0, 'total' => 0, 'items' => array(), 'warning_items' => array(), 'unmatched_programs' => array(), 'unmatched_classes' => array(), 'warning_groups' => array());
    $seen_codes = array();
    $warning_items = array();
    $normal_items = array();
    foreach ($rows as $row) {
        $item = ieum_import_preview_item($academy_id, $row, $update_existing);
        $result['total']++;
        $result[$item['status']] = isset($result[$item['status']]) ? $result[$item['status']] + 1 : 1;
        if (!empty($item['student_code'])) {
            if (isset($seen_codes[$item['student_code']])) {
                $item['warnings'][] = '파일 안 원생번호 중복';
                $result['duplicate']++;
            } else {
                $seen_codes[$item['student_code']] = true;
            }
        }
        if (!empty($item['warnings'])) {
            $result['warning']++;
            foreach ($item['warnings'] as $warning) {
                $meta = ieum_import_warning_meta($warning);
                if (!isset($result['warning_groups'][$warning])) {
                    $result['warning_groups'][$warning] = array(
                        'warning' => $warning,
                        'title' => $meta['title'],
                        'action' => $meta['action'],
                        'order' => (int) $meta['order'],
                        'count' => 0,
                    );
                }
                $result['warning_groups'][$warning]['count']++;
            }
        }
        if (!empty($item['program_text']) && empty($item['program_matched'])) {
            $program_key = $item['program_key'];
            if (!isset($result['unmatched_programs'][$program_key])) {
                $result['unmatched_programs'][$program_key] = array('text' => $item['program_text'], 'count' => 0);
            }
            $result['unmatched_programs'][$program_key]['count']++;
        }
        if (!empty($item['class_text']) && empty($item['class_time_id'])) {
            $class_key = $item['class_key'];
            if (!isset($result['unmatched_classes'][$class_key])) {
                $result['unmatched_classes'][$class_key] = array('text' => $item['class_text'], 'count' => 0);
            }
            $result['unmatched_classes'][$class_key]['count']++;
        }
        if (!empty($item['warnings'])) {
            $warning_items[] = $item;
        } elseif (count($normal_items) < 80) {
            $normal_items[] = $item;
        }
    }
    uasort($result['warning_groups'], function ($a, $b) {
        if ((int) $a['order'] === (int) $b['order']) {
            return strcmp($a['warning'], $b['warning']);
        }
        return (int) $a['order'] - (int) $b['order'];
    });
    usort($warning_items, function ($a, $b) {
        $a_order = ieum_import_warning_order(isset($a['warnings']) ? $a['warnings'] : array());
        $b_order = ieum_import_warning_order(isset($b['warnings']) ? $b['warnings'] : array());
        if ($a_order === $b_order) {
            return strcmp((string) $a['student_name'], (string) $b['student_name']);
        }
        return $a_order - $b_order;
    });
    $result['warning_items'] = $warning_items;
    $result['items'] = array_slice(array_merge($warning_items, $normal_items), 0, 80);
    return $result;
}

function ieum_import_preview_dir()
{
    $dir = G5_DATA_PATH . '/ieum/import_preview';
    if (!is_dir($dir)) {
        @mkdir($dir, G5_DIR_PERMISSION, true);
        @chmod($dir, G5_DIR_PERMISSION);
    }
    return $dir;
}

function ieum_import_cleanup_old_previews()
{
    foreach (glob(ieum_import_preview_dir() . '/*.json') as $path) {
        if (is_file($path) && time() - filemtime($path) > 10800) {
            @unlink($path);
        }
    }
}

function ieum_import_save_preview($academy_id, $rows, $update_existing, $filename)
{
    $token = bin2hex(function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16));
    $payload = array(
        'academy_id' => (int) $academy_id,
        'created_at' => time(),
        'filename' => $filename,
        'update_existing' => $update_existing ? 1 : 0,
        'rows' => array_values($rows),
    );
    file_put_contents(ieum_import_preview_dir() . '/' . $token . '.json', json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $token;
}

function ieum_import_load_preview($academy_id, $token)
{
    $token = preg_replace('/[^0-9a-f]/', '', (string) $token);
    if ($token === '') {
        throw new RuntimeException('가져오기 검토 정보가 없습니다. 파일을 다시 선택해 주세요.');
    }
    $path = ieum_import_preview_dir() . '/' . $token . '.json';
    if (!is_file($path)) {
        throw new RuntimeException('가져오기 검토 정보가 만료되었습니다. 파일을 다시 선택해 주세요.');
    }
    $payload = json_decode((string) file_get_contents($path), true);
    if (!$payload || (int) $payload['academy_id'] !== (int) $academy_id || time() - (int) $payload['created_at'] > 10800) {
        @unlink($path);
        throw new RuntimeException('가져오기 검토 정보가 만료되었습니다. 파일을 다시 선택해 주세요.');
    }
    return array($payload, $path);
}

function ieum_import_student($academy_id, $row, $update_existing)
{
    $payload = ieum_import_row_payload($academy_id, $row);
    $student_name = $payload['student_name'];
    $student_code = $payload['student_code'];
    $guardian1 = $payload['guardian1'];
    $guardian2 = $payload['guardian2'];
    $student_phone = $payload['student_phone'];
    if ($student_name === '' || $student_code === '') {
        return array('status' => 'skip', 'message' => '원생명 또는 원생번호 없음');
    }

    $academy_id = (int) $academy_id;
    $existing_id = ieum_import_existing_student_id($academy_id, $student_code);
    if ($existing_id && !$update_existing) {
        return array('status' => 'skip', 'message' => '이미 같은 원생번호가 있음', 'student_code' => $student_code, 'student_name' => $student_name);
    }

    $fields = array(
        'student_code' => $student_code,
        'student_name' => $student_name,
        'student_phone' => $student_phone,
        'birth_date' => $payload['birth_date'],
        'program_code' => $payload['program_code'],
        'school_name' => $payload['school_name'],
        'grade_group' => $payload['grade_group'],
        'class_time_id' => $payload['class_time_id'],
        'attendance_week_type' => $payload['attendance_week_type'],
        'attendance_days' => $payload['attendance_days'],
        'admission_date' => $payload['admission_date'],
        'student_status' => 'enrolled',
        'enrollment_source' => 'excel_import',
        'tuition_week_type' => $payload['attendance_week_type'],
        'tuition_amount' => $payload['tuition_amount'],
        'tuition_due_day' => $payload['tuition_due_day'],
        'memo' => $payload['memo'],
        'is_active' => 1,
    );

    $set_parts = array();
    foreach ($fields as $key => $value) {
        if ($value === null) {
            $set_parts[] = "{$key} = null";
        } else {
            $set_parts[] = "{$key} = '" . sql_escape_string($value) . "'";
        }
    }

    if ($existing_id) {
        $student_id = $existing_id;
        sql_query("
            update " . IEUM_STUDENT_TABLE . "
               set " . implode(",\n                   ", $set_parts) . ",
                   updated_at = '" . G5_TIME_YMDHIS . "'
             where academy_id = '{$academy_id}'
               and student_id = '{$student_id}'
        ");
        $status = 'update';
    } else {
        sql_query("
            insert into " . IEUM_STUDENT_TABLE . "
               set academy_id = '{$academy_id}',
                   " . implode(",\n                   ", $set_parts) . ",
                   created_at = '" . G5_TIME_YMDHIS . "',
                   updated_at = null
        ");
        $student_id = (int) sql_insert_id();
        $status = 'insert';
    }

    sql_query("
        update " . IEUM_STUDENT_GUARDIAN_TABLE . "
           set is_active = 0,
               updated_at = '" . G5_TIME_YMDHIS . "'
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
    ");
    $guardians = array_filter(array($guardian1, $guardian2));
    $sort = 0;
    foreach ($guardians as $guardian) {
        $name_sql = sql_escape_string($guardian['name']);
        $relation_sql = sql_escape_string($guardian['relation']);
        $phone_sql = sql_escape_string($guardian['phone']);
        $primary = $sort === 0 ? 1 : 0;
        $use_code = $sort === 0 ? 1 : 0;
        sql_query("
            insert into " . IEUM_STUDENT_GUARDIAN_TABLE . "
               set academy_id = '{$academy_id}',
                   student_id = '{$student_id}',
                   guardian_name = '{$name_sql}',
                   guardian_relation = '{$relation_sql}',
                   guardian_phone = '{$phone_sql}',
                   sms_attendance = 1,
                   sms_checkout = 0,
                   sms_tuition = 1,
                   use_for_student_code = '{$use_code}',
                   is_primary = '{$primary}',
                   sort_order = '{$sort}',
                   is_active = 1,
                   created_at = '" . G5_TIME_YMDHIS . "',
                   updated_at = null
        ");
        $sort++;
    }

    return array('status' => $status, 'student_id' => $student_id, 'student_code' => $student_code, 'student_name' => $student_name);
}

function ieum_import_download_warning_rows($academy_id, $rows, $update_existing)
{
    $preview = ieum_import_preview_result($academy_id, $rows, $update_existing);
    $download_rows = array();
    $warning_items = isset($preview['warning_items']) ? $preview['warning_items'] : $preview['items'];
    foreach ($warning_items as $item) {
        if (empty($item['warnings'])) {
            continue;
        }
        $download_rows[] = array(
            ieum_import_status_label($item['status']),
            $item['student_code'],
            $item['student_name'],
            $item['program_label'] !== '' ? $item['program_label'] : $item['program_text'],
            $item['school_name'],
            $item['grade_label'],
            $item['class_label'] !== '' ? $item['class_label'] : $item['class_text'],
            $item['attendance_days_label'] !== '' ? $item['attendance_days_label'] : $item['attendance_days'],
            $item['guardian_phone'],
            implode(' / ', $item['warnings']),
        );
    }

    ieum_import_send_csv('아이이음_확인필요_원생_' . date('Ymd') . '.csv', array(
        '상태',
        '학생번호',
        '학생명',
        '프로그램',
        '학교',
        '학년/부',
        '수업 부',
        '출석 요일',
        '보호자 연락처',
        '확인 필요 내용',
    ), $download_rows);
}

if (isset($_GET['download']) && $_GET['download'] === 'template') {
    ieum_import_send_csv('아이이음_원생등록_템플릿.csv', ieum_import_headers(), array(
        array('1001', '김아이', '2017-05-06', '', '태권도', '아이이음초등학교', '초등 3학년', '1부 14:10', '월,수,금', date('Y-m-d'), '150000', '5', '김보호', '010-0000-0000', '보호자', '', '', '', '이전 업체 메모', '미이용', '', ''),
    ));
}

if (isset($_GET['download']) && $_GET['download'] === 'students') {
    $rows = array();
    $result = sql_query("
        select s.*,
               c.class_name,
               c.start_time,
               (select concat(g.guardian_name, '|', g.guardian_phone, '|', g.guardian_relation)
                  from " . IEUM_STUDENT_GUARDIAN_TABLE . " g
                 where g.academy_id = s.academy_id and g.student_id = s.student_id and g.is_active = 1
              order by g.is_primary desc, g.sort_order asc, g.guardian_id asc
                 limit 1) as guardian1
          from " . IEUM_STUDENT_TABLE . " s
     left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
         where s.academy_id = '{$academy_id}'
      order by s.is_active desc, s.student_name asc, s.student_code asc
    ", false);
    while ($student = sql_fetch_array($result)) {
        $guardian = explode('|', (string) $student['guardian1']);
        $rows[] = array(
            $student['student_code'],
            $student['student_name'],
            $student['birth_date'],
            $student['student_phone'],
            ieum_program_label($academy_id, $student['program_code']),
            $student['school_name'],
            ieum_import_grade_label($student['grade_group']),
            trim($student['class_name'] . ' ' . $student['start_time']),
            ieum_import_attendance_days_label($student['attendance_days']),
            $student['admission_date'],
            $student['tuition_amount'],
            $student['tuition_due_day'],
            isset($guardian[0]) ? $guardian[0] : '',
            isset($guardian[1]) ? $guardian[1] : '',
            isset($guardian[2]) ? $guardian[2] : '',
            '', '', '',
            $student['memo'],
            '',
            '',
            '',
        );
    }
    ieum_import_send_csv('아이이음_원생목록_' . date('Ymd') . '.csv', ieum_import_headers(), $rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ieum_import_cleanup_old_previews();
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!ieum_verify_csrf_token($token)) {
        $error = '잘못된 요청입니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : 'preview';
        try {
            if ($action === 'download_warnings') {
                list($payload) = ieum_import_load_preview($academy_id, isset($_POST['preview_token']) ? $_POST['preview_token'] : '');
                $rows = isset($payload['rows']) && is_array($payload['rows']) ? $payload['rows'] : array();
                $update_existing = !empty($payload['update_existing']);
                ieum_import_download_warning_rows($academy_id, $rows, $update_existing);
            } elseif ($action === 'commit') {
                list($payload, $preview_path) = ieum_import_load_preview($academy_id, isset($_POST['preview_token']) ? $_POST['preview_token'] : '');
                $rows = isset($payload['rows']) && is_array($payload['rows']) ? $payload['rows'] : array();
                $update_existing = !empty($payload['update_existing']);
                $program_maps = isset($_POST['program_map']) && is_array($_POST['program_map']) ? $_POST['program_map'] : array();
                $class_maps = isset($_POST['class_map']) && is_array($_POST['class_map']) ? $_POST['class_map'] : array();
                $preview_check = ieum_import_preview_result($academy_id, $rows, $update_existing);
                $result = array('insert' => 0, 'update' => 0, 'skip' => 0, 'warning' => (int) $preview_check['warning'], 'items' => array());
                foreach ($rows as $row) {
                    $payload_for_map = ieum_import_row_payload($academy_id, $row);
                    if (!empty($payload_for_map['program_text']) && empty($payload_for_map['program_matched'])) {
                        $program_key = ieum_import_program_map_key($payload_for_map['program_text']);
                        $mapped_program_code = isset($program_maps[$program_key]) ? ieum_program_code($program_maps[$program_key]) : '';
                        if ($mapped_program_code !== '') {
                            $row['__program_code'] = $mapped_program_code;
                            ieum_import_save_mapping($academy_id, 'program', $payload_for_map['program_text'], $mapped_program_code);
                        }
                    }
                    if (!empty($payload_for_map['class_text']) && empty($payload_for_map['class_time_id'])) {
                        $class_key = ieum_import_class_map_key($payload_for_map['class_text']);
                        $mapped_class_id = isset($class_maps[$class_key]) ? (int) $class_maps[$class_key] : 0;
                        if ($mapped_class_id > 0) {
                            $row['__class_time_id'] = $mapped_class_id;
                            ieum_import_save_mapping($academy_id, 'class', $payload_for_map['class_text'], (string) $mapped_class_id);
                        }
                    }
                    $saved = ieum_import_student($academy_id, $row, $update_existing);
                    $result[$saved['status']] = isset($result[$saved['status']]) ? $result[$saved['status']] + 1 : 1;
                    if (count($result['items']) < 50) {
                        $result['items'][] = $saved;
                    }
                }
                @unlink($preview_path);
                $import_result = $result;
                ieum_import_save_log($academy_id, isset($payload['filename']) ? $payload['filename'] : '', $result, isset($member['mb_id']) ? $member['mb_id'] : '');
                $message = number_format($result['insert']) . '명 등록, ' . number_format($result['update']) . '명 수정, ' . number_format($result['skip']) . '명 건너뜀';
            } elseif (empty($_FILES['student_file']['tmp_name'])) {
                $error = '업로드할 파일을 선택하세요.';
            } else {
                $rows = ieum_import_csv_rows($_FILES['student_file']['tmp_name']);
                if (!$rows) {
                    throw new RuntimeException('읽을 수 있는 원생 데이터가 없습니다. 첫 줄에 학생번호, 원생이름 같은 제목이 있는지 확인해 주세요.');
                }
                $update_existing = !empty($_POST['update_existing']);
                $preview_result = ieum_import_preview_result($academy_id, $rows, $update_existing);
                $preview_token = ieum_import_save_preview($academy_id, $rows, $update_existing, isset($_FILES['student_file']['name']) ? $_FILES['student_file']['name'] : '');
                $message = '가져오기 전에 검토해 주세요. 아직 원생 정보에는 반영되지 않았습니다.';
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$program_options = ieum_program_options($academy_id);
$class_options = ieum_import_class_options($academy_id);
$recent_import_logs = ieum_import_recent_logs($academy_id);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>아이이음 원생 엑셀 가져오기</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f6f8;color:#071225;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.wrap{max-width:1900px;margin:0 auto;padding:32px 20px 56px}
.page-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}
h1{margin:0;font-size:34px;letter-spacing:0}
.muted{color:#667085}
.panel{background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:22px;box-shadow:0 12px 30px rgba(15,23,42,.05);margin-bottom:16px}
.grid{display:grid;grid-template-columns:1.1fr .9fr;gap:16px;align-items:start}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 14px;border:1px solid #cfd6df;border-radius:9px;background:#fff;color:#111827;text-decoration:none;font-weight:900;cursor:pointer}
.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}
.btn.dark{background:#111827;border-color:#111827;color:#fff}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.notice{border-radius:10px;padding:12px 14px;font-weight:900;margin:0 0 14px}
.notice.ok{background:#eef9f1;color:#176b2c;border:1px solid #b7e2c1}
.notice.err{background:#fff1f1;color:#a4262c;border:1px solid #ffc9c9}
.upload-box{display:grid;gap:14px}
input[type=file]{border:1px dashed #b8c4d6;border-radius:12px;background:#f8fbff;padding:18px;width:100%}
.check{display:inline-flex;gap:8px;align-items:center;font-weight:900;color:#344054}
.step-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:14px 0 0}
.step{border:1px solid #e4eaf2;border-radius:12px;padding:13px;background:#f8fafc}
.step strong{display:block;color:#1769c2;margin-bottom:4px}
table{width:100%;border-collapse:collapse;background:#fff}
th,td{border:1px solid #d8dee9;padding:9px 10px;text-align:center}
th{background:#667893;color:#fff}
td.left{text-align:left}
.map-table th:first-child,.map-table td:first-child{text-align:left}
.result-list{display:grid;gap:6px;margin-top:12px}
.result-item{border:1px solid #e4eaf2;border-radius:10px;padding:9px 10px;background:#f8fafc;font-size:13px}
.summary-cards{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin:14px 0 16px}
.summary-card{border:1px solid #d9e1ed;border-radius:12px;background:#f8fbff;padding:13px}
.summary-card.good{border-color:#b7e2c1;background:#eef9f1}
.summary-card.info{border-color:#b9d8ff;background:#eef6ff}
.summary-card.warn{border-color:#ffd59d;background:#fff8e8}
.summary-card.danger{border-color:#ffc9c9;background:#fff1f1}
.summary-card span{display:block;color:#667085;font-size:13px;font-weight:800}
.summary-card strong{display:block;margin-top:4px;font-size:24px}
.review-note{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 16px}
.review-note div{border:1px solid #d9e5f8;border-radius:12px;background:#f8fbff;padding:13px;color:#344054;line-height:1.5}
.review-note strong{display:block;color:#174a8b;margin-bottom:4px}
.review-flow{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:0 0 16px}
.review-flow div{border:1px solid #e4eaf2;border-radius:12px;background:#fff;padding:13px;line-height:1.45}
.review-flow strong{display:block;color:#101828;margin-bottom:4px}
.review-flow span{color:#667085;font-size:13px}
.warning-board{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:0 0 16px}
.warning-card{border:1px solid #ffd59d;border-radius:12px;background:#fffaf0;padding:13px;line-height:1.45}
.warning-card strong{display:flex;justify-content:space-between;gap:10px;color:#101828;margin-bottom:5px}
.warning-card em{font-style:normal;color:#8a5200}
.warning-card span{display:block;color:#667085;font-size:13px}
.review-alert{border:1px solid #ffd59d;border-radius:12px;background:#fff8e8;color:#8a5200;padding:13px 14px;margin:0 0 14px;font-weight:800;line-height:1.55}
.review-alert strong{color:#6f3f00}
.preview-table-wrap{overflow:auto;border:1px solid #d8dee9;border-radius:12px}
.preview-table{min-width:1100px}
.preview-table tr.has-warning td{background:#fffaf0}
.preview-table tr.is-skip td{background:#f8fafc;color:#667085}
.preview-table td.status-cell{white-space:nowrap}
.preview-table td.need-check{text-align:left;min-width:180px}
.preview-table td.student-cell{text-align:left;font-weight:900}
.map-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:12px 0 16px}
.map-row{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(190px,.9fr);gap:10px;align-items:center;border:1px solid #e4eaf2;border-radius:12px;background:#f8fafc;padding:12px}
.map-row strong{display:block;color:#101828}
.map-row span{display:block;color:#667085;font-size:13px;margin-top:3px}
.map-row select{width:100%;min-height:42px;border:1px solid #cfd6df;border-radius:9px;padding:8px;background:#fff;font-weight:800}
.warning-text{display:inline-flex;margin:2px 3px 2px 0;padding:3px 8px;border-radius:999px;background:#fff1c7;color:#8a5200;font-size:12px;font-weight:900}
.ok-text{display:inline-flex;padding:3px 8px;border-radius:999px;background:#eef9f1;color:#176b2c;font-size:12px;font-weight:900}
.badge{display:inline-flex;border-radius:999px;padding:3px 8px;font-weight:900;font-size:12px;margin-right:6px}
.badge.insert{background:#eef9f1;color:#176b2c}.badge.update{background:#eaf4ff;color:#1769c2}.badge.skip{background:#fff6df;color:#9a5b00}
@media(max-width:900px){.grid{grid-template-columns:1fr}.page-head{display:grid}h1{font-size:28px}.summary-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.map-list,.review-note,.review-flow,.warning-board,.step-list{grid-template-columns:1fr}.map-row{grid-template-columns:1fr}}
/* Dashboard shell alignment: keep import work inside the same calm frame. */
body.ieum-side-layout.ieum-dashboard-page.import-page-tune{
    --ieum-side-width:260px!important;
    --ieum-rail-width:0px!important;
    --ieum-top-height:64px!important;
    --ieum-shell-top:#fff!important;
    --ieum-side-bg:#fff!important;
    background:#f4f6f9!important;
    color:#1f2937!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .ieum-side{
    width:260px!important;
    background:var(--ieum-side-bg)!important;
    border-right:1px solid #e7ebf0!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .side-brand{
    height:144px!important;
    min-height:144px!important;
    background:var(--ieum-side-bg)!important;
    color:#111827!important;
    padding:0 26px!important;
    font-size:29px!important;
    letter-spacing:0!important;
    border-bottom:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .side-search{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .side-nav{
    padding:0 14px 24px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .side-main-link,
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .side-menu>summary{
    min-height:42px!important;
    padding:0 12px!important;
    border-left:0!important;
    border-radius:6px!important;
    font-size:14px!important;
    font-weight:700!important;
    color:#243142!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .side-main-link:hover,
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .side-main-link.active,
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .side-menu[open]>summary,
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .side-menu>summary:hover{
    background:#f4f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .ieum-nav-label{
    gap:8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .ieum-nav-icon{
    width:17px!important;
    height:17px!important;
    flex:0 0 17px!important;
    color:#334155!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .side-sub{
    background:#fff!important;
    border:0!important;
    padding:2px 0 8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .side-sub a{
    min-height:32px!important;
    padding:0 12px 0 34px!important;
    font-size:13px!important;
    font-weight:600!important;
    color:#4b5563!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .side-sub a:hover,
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .side-sub a.active{
    background:#f4f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .ieum-shell-top{
    left:260px!important;
    right:0!important;
    height:64px!important;
    background:var(--ieum-side-bg)!important;
    color:#1f2937!important;
    border-bottom:0!important;
    box-shadow:none!important;
    padding:0 38px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .ieum-shell-link{
    min-height:34px!important;
    border:0!important;
    background:transparent!important;
    color:#1f2937!important;
    padding:0 10px!important;
    border-radius:4px!important;
    font-size:13px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .ieum-shell-link:before{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .ieum-shell-link:hover{
    background:#f4f7fb!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .ieum-shell-meta{
    color:#374151!important;
    font-size:13px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .dashboard-shell-meta-inner{
    display:inline-flex!important;
    align-items:center!important;
    justify-content:flex-end!important;
    gap:8px!important;
    white-space:nowrap!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .dashboard-shell-divider,
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .dashboard-shell-help-dot{
    color:#c3cad5!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .dashboard-shell-clock{
    font-weight:900!important;
    color:#243142!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .dashboard-shell-support-link{
    display:inline-flex!important;
    align-items:center!important;
    min-height:26px!important;
    color:#1f2937!important;
    text-decoration:none!important;
    font-size:13px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .dashboard-shell-support-link:hover{
    color:#1583e9!important;
    text-decoration:underline!important;
    text-underline-offset:3px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .dashboard-shell-help-group{
    display:inline-flex!important;
    align-items:center!important;
    gap:6px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .ieum-right-rail{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .wrap{
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
    max-width:none!important;
    width:auto!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .page-head{
    align-items:flex-end!important;
    margin-bottom:18px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune h1{
    font-size:30px!important;
    font-weight:1000!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .page-head .muted{
    max-width:760px!important;
    margin:8px 0 0!important;
    line-height:1.45!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .actions{
    align-items:center!important;
    gap:8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .btn{
    min-height:38px!important;
    border-radius:6px!important;
    font-size:14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .grid{
    grid-template-columns:minmax(440px,.92fr) minmax(430px,1.08fr)!important;
    gap:16px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .panel{
    border-radius:8px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
    border-color:#dfe5ee!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .panel h2{
    margin-top:0!important;
    font-size:22px!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .upload-box{
    border:1px solid #e1e8f2!important;
    border-radius:8px!important;
    background:#fbfcff!important;
    padding:14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune input[type=file]{
    min-height:78px!important;
    border-radius:8px!important;
    background:#fff!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .check{
    padding:10px 12px!important;
    border:1px solid #e1e8f2!important;
    border-radius:8px!important;
    background:#fff!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .step-list{
    grid-template-columns:repeat(3,minmax(0,1fr))!important;
    gap:8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .step{
    border-radius:8px!important;
    background:#f8fafc!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .map-table th{
    height:40px!important;
    background:#f3f6fb!important;
    color:#334155!important;
    border-color:#e2e8f0!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .map-table td{
    border-color:#e5ebf3!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .result-item{
    border-radius:8px!important;
    background:#fbfcff!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .summary-cards{
    grid-template-columns:repeat(6,minmax(0,1fr))!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .summary-card,
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .review-flow div,
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .review-note div,
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .warning-card,
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .map-row{
    border-radius:8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .preview-table-wrap{
    border-radius:8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.import-page-tune .preview-table th{
    background:#667893!important;
}
@media(max-width:1500px){
    body.ieum-side-layout.ieum-dashboard-page.import-page-tune .grid{
        grid-template-columns:1fr!important;
    }
}
@media(max-width:1280px){
    body.ieum-side-layout.ieum-dashboard-page.import-page-tune .summary-cards{
        grid-template-columns:repeat(3,minmax(0,1fr))!important;
    }
}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.import-page-tune .wrap{
        margin:0!important;
        padding:86px 14px 34px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.import-page-tune .ieum-shell-top{
        left:0!important;
        right:0!important;
        padding:0 10px!important;
    }
}
@media(max-width:680px){
    body.ieum-side-layout.ieum-dashboard-page.import-page-tune .summary-cards,
    body.ieum-side-layout.ieum-dashboard-page.import-page-tune .step-list{
        grid-template-columns:1fr!important;
    }
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page import-page-tune">
<?php echo ieum_admin_header('student_import', 'side'); ?>
<main class="wrap">
    <div class="page-head">
        <div>
            <h1>엑셀 가져오기</h1>
            <p class="muted"><?php echo get_text($academy['academy_name']); ?> · 에듀패밀리 등 기존 업체 원생 목록을 아이이음 원생관리로 옮깁니다.</p>
        </div>
        <div class="actions">
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/students.php">원생 관리</a>
            <a class="btn dark" href="<?php echo IEUM_URL; ?>/admin/student_import.php?download=students">현재 원생 다운로드</a>
            <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/student_import.php?download=template">업로드 양식 다운로드</a>
        </div>
    </div>

    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="grid">
        <article class="panel">
            <h2>업로드</h2>
            <p class="muted">파일을 올리면 바로 저장하지 않고, 먼저 검토 화면에서 신규·수정·확인 필요 원생을 보여드립니다.</p>
            <form method="post" enctype="multipart/form-data" class="upload-box">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="preview">
                <input type="file" name="student_file" accept=".xls,.xlsx,.csv,.txt,.tsv">
                <label class="check"><input type="checkbox" name="update_existing" value="1" checked> 같은 원생번호가 있으면 기존 원생 정보를 업데이트</label>
                <button class="btn primary" type="submit">가져오기 검토</button>
            </form>
            <div class="step-list">
                <div class="step"><strong>1. 파일 선택</strong>에듀패밀리 .xls, 엑셀 .xlsx, 아이이음 CSV 파일을 그대로 올립니다.</div>
                <div class="step"><strong>2. 검토</strong>신규 등록, 기존 수정, 확인 필요 원생을 저장 전에 먼저 확인합니다.</div>
                <div class="step"><strong>3. 최종 반영</strong>프로그램·수업 부를 한 번 맞추면 다음 업로드부터 자동으로 적용됩니다.</div>
            </div>
        </article>
        <article class="panel">
            <h2>에듀패밀리 → 아이이음 매핑</h2>
            <table class="map-table">
                <thead><tr><th>에듀패밀리 컬럼</th><th>아이이음 반영 위치</th></tr></thead>
                <tbody>
                    <tr><td>학생번호</td><td>원생번호</td></tr>
                    <tr><td>원생이름</td><td>원생명</td></tr>
                    <tr><td>생일</td><td>생년월일</td></tr>
                    <tr><td>출석일</td><td>출석 요일</td></tr>
                    <tr><td>가입일</td><td>입관일</td></tr>
                    <tr><td>학교명 / 학년</td><td>학교 / 학년·부</td></tr>
                    <tr><td>기본수강료 / 수납일</td><td>수련비 / 매월 납부일</td></tr>
                    <tr><td>부모1, 부모2</td><td>보호자 연락처</td></tr>
                    <tr><td>성별, 주소, 반, 특이사항</td><td>메모에 보관</td></tr>
                </tbody>
            </table>
            <h3 style="margin-top:22px">최근 가져오기 이력</h3>
            <?php if ($recent_import_logs) { ?>
            <div class="result-list">
                <?php foreach ($recent_import_logs as $log) { ?>
                <div class="result-item">
                    <strong><?php echo get_text($log['created_at']); ?></strong>
                    <span class="muted"><?php echo get_text($log['filename']); ?></span><br>
                    등록 <?php echo number_format((int) $log['insert_count']); ?>명 · 수정 <?php echo number_format((int) $log['update_count']); ?>명 · 건너뜀 <?php echo number_format((int) $log['skip_count']); ?>명 · 확인필요 <?php echo number_format((int) $log['warning_count']); ?>명
                </div>
                <?php } ?>
            </div>
            <?php } else { ?>
            <p class="muted">아직 가져오기 이력이 없습니다.</p>
            <?php } ?>
        </article>
    </section>

    <?php if ($preview_result) { ?>
    <section class="panel">
        <h2>가져오기 검토</h2>
        <p class="muted">아직 저장되지 않았습니다. 아래 예상 결과를 확인한 뒤 최종 반영을 눌러 주세요.</p>
        <div class="summary-cards">
            <div class="summary-card"><span>전체 행</span><strong><?php echo number_format((int) $preview_result['total']); ?></strong></div>
            <div class="summary-card good"><span>신규 등록</span><strong><?php echo number_format((int) $preview_result['insert']); ?></strong></div>
            <div class="summary-card info"><span>기존 수정</span><strong><?php echo number_format((int) $preview_result['update']); ?></strong></div>
            <div class="summary-card warn"><span>건너뜀</span><strong><?php echo number_format((int) $preview_result['skip']); ?></strong></div>
            <div class="summary-card <?php echo !empty($preview_result['warning']) ? 'danger' : 'good'; ?>"><span>확인 필요</span><strong><?php echo number_format((int) $preview_result['warning']); ?></strong></div>
            <div class="summary-card <?php echo !empty($preview_result['duplicate']) ? 'danger' : ''; ?>"><span>파일 내 중복</span><strong><?php echo number_format((int) $preview_result['duplicate']); ?></strong></div>
        </div>
        <div class="review-flow">
            <div><strong>1. 숫자 먼저 확인</strong><span>신규 등록, 기존 수정, 건너뜀 숫자가 예상과 맞는지 봅니다.</span></div>
            <div><strong>2. 노란 표시 정리</strong><span>확인 필요가 있으면 원생번호, 보호자 연락처, 프로그램, 수업부를 먼저 확인합니다.</span></div>
            <div><strong>3. 최종 반영</strong><span>검토가 끝나면 아래 버튼으로 원생관리 자료에 저장합니다.</span></div>
        </div>
        <?php if (!empty($preview_result['warning_groups'])) { ?>
        <div class="warning-board">
            <?php foreach ($preview_result['warning_groups'] as $warning_group) { ?>
            <div class="warning-card">
                <strong><?php echo get_text($warning_group['title']); ?> <em><?php echo number_format((int) $warning_group['count']); ?>명</em></strong>
                <span><?php echo get_text($warning_group['action']); ?></span>
            </div>
            <?php } ?>
        </div>
        <?php } ?>
        <div class="review-note">
            <div><strong>안전하게 가져오기</strong>이 화면은 미리보기입니다. 최종 반영 전까지 원생관리에는 저장되지 않으니, 신규/수정/건너뜀 숫자를 먼저 확인하세요.</div>
            <div><strong>이전 업체 정보 보존</strong>차량, 형제자매, 현금영수증, 주소, 특이사항처럼 바로 쓰지 않는 정보는 원생 메모에 보관됩니다.</div>
        </div>
        <?php if (!empty($preview_result['warning'])) { ?>
        <div class="review-alert">
            <strong>확인 필요 <?php echo number_format((int) $preview_result['warning']); ?>명</strong>이 있습니다. 그대로 반영할 수도 있지만, 처음 이관 때는 확인 필요 행을 내려받아 연락처·학년·수업부를 정리한 뒤 다시 올리는 것을 권장합니다.
        </div>
        <form method="post" class="actions" style="margin-bottom:14px">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="download_warnings">
            <input type="hidden" name="preview_token" value="<?php echo get_text($preview_token); ?>">
            <button class="btn dark" type="submit">확인 필요 행 다운로드</button>
        </form>
        <?php } ?>
        <form method="post" onsubmit="return confirm('검토한 원생 정보를 최종 반영할까요?');">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="commit">
            <input type="hidden" name="preview_token" value="<?php echo get_text($preview_token); ?>">
            <?php if (!empty($preview_result['unmatched_programs'])) { ?>
            <h3>프로그램 연결</h3>
            <p class="muted">이전 업체의 종목명이 아이이음 프로그램과 다를 때 여기서 연결합니다. 같은 종목명은 한 번에 같이 적용됩니다.</p>
            <div class="map-list">
                <?php foreach ($preview_result['unmatched_programs'] as $program_key => $program_map) { ?>
                <label class="map-row">
                    <span>
                        <strong><?php echo get_text($program_map['text']); ?></strong>
                        <span><?php echo number_format((int) $program_map['count']); ?>명 적용 대상</span>
                    </span>
                    <select name="program_map[<?php echo get_text($program_key); ?>]">
                        <option value="">프로그램 선택 안 함</option>
                        <?php foreach ($program_options as $program_option) { ?>
                        <option value="<?php echo get_text($program_option['program_code']); ?>"><?php echo get_text($program_option['program_name']); ?></option>
                        <?php } ?>
                    </select>
                </label>
                <?php } ?>
            </div>
            <?php } ?>
            <?php if (!empty($preview_result['unmatched_classes'])) { ?>
            <h3>수업부 연결</h3>
            <p class="muted">이전 업체의 반 이름이 아이이음 수업부와 다를 때 여기서 한 번만 연결하면 같은 반 이름의 원생들에게 같이 적용됩니다.</p>
            <div class="map-list">
                <?php foreach ($preview_result['unmatched_classes'] as $class_key => $class_map) { ?>
                <label class="map-row">
                    <span>
                        <strong><?php echo get_text($class_map['text']); ?></strong>
                        <span><?php echo number_format((int) $class_map['count']); ?>명 적용 대상</span>
                    </span>
                    <select name="class_map[<?php echo get_text($class_key); ?>]">
                        <option value="0">수업부 선택 안 함</option>
                        <?php foreach ($class_options as $class_option) { ?>
                        <option value="<?php echo (int) $class_option['class_time_id']; ?>"><?php echo get_text($class_option['label']); ?></option>
                        <?php } ?>
                    </select>
                </label>
                <?php } ?>
            </div>
            <?php } ?>
            <div class="actions" style="margin-bottom:14px">
                <button class="btn primary" type="submit">검토 결과 최종 반영</button>
                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/student_import.php">다시 파일 선택</a>
            </div>
        <div class="preview-table-wrap">
            <table class="preview-table">
                <thead><tr><th>상태</th><th>원생번호</th><th>원생명</th><th>프로그램</th><th>학교</th><th>학년</th><th>수업부</th><th>요일</th><th>보호자 연락처</th><th>확인 필요</th></tr></thead>
                <tbody>
                <?php foreach ($preview_result['items'] as $item) { ?>
                    <tr class="<?php echo !empty($item['warnings']) ? 'has-warning' : ''; ?> <?php echo $item['status'] === 'skip' ? 'is-skip' : ''; ?>">
                        <td class="status-cell"><span class="badge <?php echo get_text($item['status']); ?>"><?php echo get_text(ieum_import_status_label($item['status'])); ?></span></td>
                        <td><?php echo get_text($item['student_code']); ?></td>
                        <td class="student-cell"><?php echo get_text($item['student_name']); ?></td>
                        <td><?php echo get_text($item['program_label'] !== '' ? $item['program_label'] : $item['program_text']); ?></td>
                        <td><?php echo get_text($item['school_name']); ?></td>
                        <td><?php echo get_text($item['grade_label']); ?></td>
                        <td><?php echo get_text($item['class_label'] !== '' ? $item['class_label'] : $item['class_text']); ?></td>
                        <td><?php echo get_text($item['attendance_days_label'] !== '' ? $item['attendance_days_label'] : $item['attendance_days']); ?></td>
                        <td><?php echo get_text($item['guardian_phone']); ?></td>
                        <td class="need-check">
                            <?php if (empty($item['warnings'])) { ?>
                                <span class="ok-text">확인 완료</span>
                            <?php } ?>
                            <?php foreach ($item['warnings'] as $warning) { ?>
                                <span class="warning-text"><?php echo get_text($warning); ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        </form>
    </section>
    <?php } ?>

    <?php if ($import_result) { ?>
    <section class="panel">
        <h2>최종 처리 결과</h2>
        <div class="result-list">
            <?php foreach ($import_result['items'] as $item) { ?>
            <div class="result-item">
                <span class="badge <?php echo get_text($item['status']); ?>"><?php echo get_text(ieum_import_status_label($item['status'])); ?></span>
                <?php echo get_text(isset($item['student_name']) ? $item['student_name'] : '-'); ?>
                <?php if (!empty($item['student_code'])) { ?> · <?php echo get_text($item['student_code']); ?><?php } ?>
                <?php if (!empty($item['message'])) { ?> · <?php echo get_text($item['message']); ?><?php } ?>
            </div>
            <?php } ?>
        </div>
    </section>
    <?php } ?>
</main>
<script>
(function() {
    var academyName = <?php echo json_encode(isset($academy['academy_name']) ? $academy['academy_name'] : '아이이음', JSON_UNESCAPED_UNICODE); ?>;
    var rootSelector = '.import-page-tune.ieum-dashboard-page';
    var brandText = document.querySelector(rootSelector + ' .side-brand span:last-child');
    if (brandText) {
        brandText.textContent = academyName;
    }
    var homeLink = document.querySelector(rootSelector + ' .ieum-shell-link');
    if (homeLink) {
        homeLink.textContent = '아이이음 교육페이지';
    }
    var meta = document.querySelector(rootSelector + ' .ieum-shell-meta');
    if (!meta) {
        return;
    }
    meta.textContent = '';
    var supportBaseUrl = <?php echo json_encode(IEUM_URL . '/admin/support.php', JSON_UNESCAPED_UNICODE); ?>;
    var metaInner = document.createElement('span');
    metaInner.className = 'dashboard-shell-meta-inner';
    var makeSupportLink = function(text, topic) {
        var link = document.createElement('a');
        link.className = 'dashboard-shell-support-link';
        link.href = supportBaseUrl + '?topic=' + encodeURIComponent(topic);
        link.textContent = text;
        return link;
    };
    var makeDivider = function() {
        var divider = document.createElement('span');
        divider.className = 'dashboard-shell-divider';
        divider.textContent = '|';
        return divider;
    };
    var helpGroup = document.createElement('span');
    helpGroup.className = 'dashboard-shell-help-group';
    [
        ['Q&A', 'qna'],
        ['자주하는 질문', 'faq'],
        ['문의하기', 'contact'],
        ['AI 챗봇', 'ai']
    ].forEach(function(item, index) {
        if (index > 0) {
            var dot = document.createElement('span');
            dot.className = 'dashboard-shell-help-dot';
            dot.textContent = '·';
            helpGroup.appendChild(dot);
        }
        helpGroup.appendChild(makeSupportLink(item[0], item[1]));
    });
    var academyText = document.createElement('span');
    academyText.textContent = academyName;
    var clockText = document.createElement('span');
    clockText.className = 'dashboard-shell-clock';
    var renderClock = function() {
        var now = new Date();
        clockText.textContent = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
    };
    metaInner.appendChild(makeSupportLink('개발지원센터', 'qna'));
    metaInner.appendChild(makeDivider());
    metaInner.appendChild(helpGroup);
    metaInner.appendChild(makeDivider());
    metaInner.appendChild(academyText);
    metaInner.appendChild(makeDivider());
    metaInner.appendChild(clockText);
    meta.appendChild(metaInner);
    renderClock();
    window.setInterval(renderClock, 30000);
})();
</script>
</body>
</html>
