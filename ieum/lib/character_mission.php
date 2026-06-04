<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_character_mission_ensure_tables()
{
    sql_query("alter table " . IEUM_STUDENT_TABLE . " add gender varchar(10) not null default 'all' after birth_date", false);
    sql_query("alter table " . IEUM_STUDENT_TABLE . " add character_report_enabled tinyint(1) not null default 1 after gender", false);
    sql_query("alter table " . IEUM_STUDENT_TABLE . " add fitness_report_enabled tinyint(1) not null default 1 after character_report_enabled", false);

    sql_query("
        create table if not exists " . IEUM_CHARACTER_MISSION_TABLE . " (
            mission_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            mission_month char(7) not null,
            mission_title varchar(100) not null default '',
            mission_theme varchar(80) not null default '',
            guide_url varchar(255) not null default '',
            guide_summary text null,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (mission_id),
            unique key uq_academy_month (academy_id, mission_month),
            key idx_academy_active (academy_id, is_active, mission_month)
        ) engine=InnoDB default charset=utf8
    ");

    sql_query("
        create table if not exists " . IEUM_CHARACTER_MISSION_STUDENT_TABLE . " (
            mission_student_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            mission_id int unsigned not null default 0,
            student_id int unsigned not null,
            mission_month char(7) not null,
            participation_status varchar(20) not null default 'none',
            proof_memo varchar(255) not null default '',
            proof_url varchar(255) not null default '',
            bonus_score tinyint unsigned not null default 0,
            checked_by varchar(50) not null default '',
            checked_at datetime null,
            created_at datetime not null,
            updated_at datetime null,
            primary key (mission_student_id),
            unique key uq_student_month (academy_id, student_id, mission_month),
            key idx_mission (academy_id, mission_id, participation_status)
        ) engine=InnoDB default charset=utf8
    ");
}

function ieum_character_mission_valid_month($month)
{
    return preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
}

function ieum_character_mission_status_options()
{
    return array(
        'none' => array('label' => '미참여', 'bonus' => 0, 'parent' => '이번 달 아이잘해 가정미션은 아직 참여 전입니다. 다음 달에는 가정에서도 함께 실천해 볼 수 있도록 도장에서 다시 안내하겠습니다.'),
        'done' => array('label' => '미션 성공', 'bonus' => 5, 'parent' => '가정에서도 아이잘해 미션을 실천했습니다. 도장 수업에서 배운 인성 주제를 집에서도 이어간 좋은 성장 경험입니다.'),
        'comment' => array('label' => '미션 성공', 'bonus' => 5, 'parent' => '가정에서도 아이잘해 미션을 실천했습니다. 도장 수업에서 배운 인성 주제를 집에서도 이어간 좋은 성장 경험입니다.'),
        'photo' => array('label' => '미션 성공', 'bonus' => 5, 'parent' => '가정에서도 아이잘해 미션을 실천했습니다. 도장 수업에서 배운 인성 주제를 집에서도 이어간 좋은 성장 경험입니다.'),
        'excellent' => array('label' => '미션 성공', 'bonus' => 5, 'parent' => '가정에서도 아이잘해 미션을 실천했습니다. 도장 수업에서 배운 인성 주제를 집에서도 이어간 좋은 성장 경험입니다.'),
    );
}

function ieum_character_mission_status_label($status)
{
    $options = ieum_character_mission_status_options();
    return isset($options[$status]) ? $options[$status]['label'] : $options['none']['label'];
}

function ieum_character_mission_bonus_score($status)
{
    $options = ieum_character_mission_status_options();
    return isset($options[$status]) ? (int) $options[$status]['bonus'] : 0;
}

function ieum_character_mission_get($academy_id, $month)
{
    $academy_id = (int) $academy_id;
    $month = sql_escape_string(ieum_character_mission_valid_month($month));

    $row = sql_fetch("
        select *
          from " . IEUM_CHARACTER_MISSION_TABLE . "
         where academy_id = '{$academy_id}'
           and mission_month = '{$month}'
         limit 1
    ", false);

    return isset($row['mission_id']) ? $row : null;
}

function ieum_character_mission_student_get($academy_id, $student_id, $month)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $month = sql_escape_string(ieum_character_mission_valid_month($month));

    $row = sql_fetch("
        select ms.*, m.mission_title, m.mission_theme, m.guide_url, m.guide_summary
          from " . IEUM_CHARACTER_MISSION_STUDENT_TABLE . " ms
     left join " . IEUM_CHARACTER_MISSION_TABLE . " m on m.mission_id = ms.mission_id and m.academy_id = ms.academy_id
         where ms.academy_id = '{$academy_id}'
           and ms.student_id = '{$student_id}'
           and ms.mission_month = '{$month}'
         limit 1
    ", false);

    return isset($row['mission_student_id']) ? $row : null;
}

function ieum_character_mission_report($academy_id, $student_id, $month)
{
    $mission = ieum_character_mission_get($academy_id, $month);
    $student_mission = ieum_character_mission_student_get($academy_id, $student_id, $month);
    $status = $student_mission ? $student_mission['participation_status'] : 'none';
    $options = ieum_character_mission_status_options();
    $status_info = isset($options[$status]) ? $options[$status] : $options['none'];

    return array(
        'mission' => $mission,
        'student_mission' => $student_mission,
        'status' => $status,
        'status_label' => $status_info['label'],
        'parent_text' => $status_info['parent'],
        'bonus_score' => ieum_character_mission_bonus_score($status),
        'is_participated' => in_array($status, array('done', 'comment', 'photo', 'excellent'), true),
    );
}
