<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_dashboard_ensure_shortcut_table()
{
    $engine = defined('G5_DB_ENGINE') && G5_DB_ENGINE ? G5_DB_ENGINE : 'InnoDB';
    $charset = defined('G5_DB_CHARSET') && G5_DB_CHARSET ? G5_DB_CHARSET : 'utf8';

    sql_query("
        create table if not exists " . IEUM_DASHBOARD_SHORTCUT_TABLE . " (
            shortcut_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            shortcut_key varchar(50) not null,
            sort_order int unsigned not null default 0,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (shortcut_id),
            unique key uq_academy_shortcut (academy_id, shortcut_key),
            key idx_academy_sort (academy_id, is_active, sort_order)
        ) engine={$engine} default charset={$charset}
    ", false);
}

function ieum_dashboard_shortcut_catalog()
{
    return array(
        'attendance_today' => array('label' => '오늘 출석', 'desc' => '등원, 미등원, 문자 상태', 'url' => IEUM_URL . '/admin/attendance_today.php'),
        'students' => array('label' => '학생 관리', 'desc' => '등록, 수정, 빠른 상담 메모', 'url' => IEUM_URL . '/admin/students.php'),
        'tuition_payments' => array('label' => '수련비 납부', 'desc' => '결제완료, 미납, 자동문자', 'url' => IEUM_URL . '/admin/tuition_payments.php'),
        'vehicle_boarding' => array('label' => '차량 탑승확인', 'desc' => '기사님 기록과 특이사항', 'url' => IEUM_URL . '/admin/vehicle_boarding.php'),
        'character' => array('label' => '인성 입력', 'desc' => '부별 주간 인성 체크', 'url' => IEUM_URL . '/admin/character.php'),
        'character_report' => array('label' => '월간 인성', 'desc' => '학부모 리포트 확인', 'url' => IEUM_URL . '/admin/character_report.php'),
        'character_mission' => array('label' => '아이잘해 미션', 'desc' => '가정 실천 참여 체크', 'url' => IEUM_URL . '/admin/character_mission.php'),
        'operations' => array('label' => '운영 지표', 'desc' => '신규, 휴관, 상담 신호', 'url' => IEUM_URL . '/admin/operations.php'),
        'growth_report' => array('label' => '원생 리포트', 'desc' => '월별 원생 흐름', 'url' => IEUM_URL . '/admin/growth_report.php'),
        'vehicle_assignments' => array('label' => '차량 배정', 'desc' => '호차별 등원/하원 배정', 'url' => IEUM_URL . '/admin/vehicle_assignments.php'),
        'tablet_devices' => array('label' => '출석기 관리', 'desc' => '앱 연결, 기기명, 해제', 'url' => IEUM_URL . '/admin/tablet_devices.php'),
    );
}

function ieum_dashboard_default_shortcut_keys()
{
    return array('attendance_today', 'students', 'tuition_payments', 'vehicle_boarding', 'character', 'operations');
}

function ieum_dashboard_get_shortcut_keys($academy_id)
{
    $academy_id = (int) $academy_id;
    ieum_dashboard_ensure_shortcut_table();

    $keys = array();
    $result = sql_query("
        select shortcut_key
          from " . IEUM_DASHBOARD_SHORTCUT_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
      order by sort_order asc, shortcut_id asc
    ", false);

    while ($row = sql_fetch_array($result)) {
        $keys[] = $row['shortcut_key'];
    }

    return $keys ? $keys : ieum_dashboard_default_shortcut_keys();
}

function ieum_dashboard_resolve_shortcuts($keys)
{
    $catalog = ieum_dashboard_shortcut_catalog();
    $items = array();

    foreach ($keys as $key) {
        if (!isset($catalog[$key])) {
            continue;
        }

        $items[$key] = $catalog[$key];
    }

    return $items;
}

function ieum_dashboard_save_shortcuts($academy_id, $keys)
{
    $academy_id = (int) $academy_id;
    $catalog = ieum_dashboard_shortcut_catalog();
    $clean = array();

    foreach ((array) $keys as $key) {
        $key = trim($key);
        if (!isset($catalog[$key]) || isset($clean[$key])) {
            continue;
        }
        $clean[$key] = $key;
        if (count($clean) >= 8) {
            break;
        }
    }

    if (!$clean) {
        $clean = array_fill_keys(ieum_dashboard_default_shortcut_keys(), true);
    }

    ieum_dashboard_ensure_shortcut_table();
    sql_query("delete from " . IEUM_DASHBOARD_SHORTCUT_TABLE . " where academy_id = '{$academy_id}'", false);

    $sort = 0;
    foreach (array_keys($clean) as $key) {
        $sort += 10;
        sql_query("
            insert into " . IEUM_DASHBOARD_SHORTCUT_TABLE . "
                set academy_id = '{$academy_id}',
                    shortcut_key = '" . sql_escape_string($key) . "',
                    sort_order = '{$sort}',
                    is_active = 1,
                    created_at = '" . G5_TIME_YMDHIS . "',
                    updated_at = '" . G5_TIME_YMDHIS . "'
        ", false);
    }
}
