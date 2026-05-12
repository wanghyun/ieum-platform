<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_program_code($value)
{
    return preg_replace('/[^0-9A-Za-z_]/', '', trim((string) $value));
}

function ieum_default_programs()
{
    return array(
        array('program_code' => 'taekwondo', 'program_name' => '태권도', 'is_default' => 1, 'sort_order' => 1),
        array('program_code' => 'hapkido', 'program_name' => '합기도', 'is_default' => 0, 'sort_order' => 2),
        array('program_code' => 'jump_rope', 'program_name' => '줄넘기', 'is_default' => 0, 'sort_order' => 3),
    );
}

function ieum_seed_default_programs($academy_id)
{
    $academy_id = (int) $academy_id;
    if (!$academy_id) {
        return;
    }

    foreach (ieum_default_programs() as $program) {
        $code = sql_escape_string($program['program_code']);
        $name = sql_escape_string($program['program_name']);
        $is_default = (int) $program['is_default'];
        $sort_order = (int) $program['sort_order'];
        sql_query("
            insert into " . IEUM_ACADEMY_PROGRAM_TABLE . "
                set academy_id = '{$academy_id}',
                    program_code = '{$code}',
                    program_name = '{$name}',
                    is_default = '{$is_default}',
                    is_active = 1,
                    sort_order = '{$sort_order}',
                    created_at = '" . G5_TIME_YMDHIS . "'
            on duplicate key update
                    program_name = if(program_name = '', values(program_name), program_name),
                    sort_order = if(sort_order = 0, values(sort_order), sort_order),
                    updated_at = '" . G5_TIME_YMDHIS . "'
        ");
    }

    ieum_normalize_default_program($academy_id);
}

function ieum_normalize_default_program($academy_id, $default_code = '')
{
    $academy_id = (int) $academy_id;
    $default_code = ieum_program_code($default_code);
    if (!$academy_id) {
        return;
    }

    if ($default_code === '') {
        $row = sql_fetch("
            select program_code
              from " . IEUM_ACADEMY_PROGRAM_TABLE . "
             where academy_id = '{$academy_id}'
               and is_active = 1
          order by is_default desc, sort_order asc, program_id asc
             limit 1
        ", false);
        $default_code = isset($row['program_code']) ? $row['program_code'] : '';
    }

    if ($default_code === '') {
        return;
    }

    $default_sql = sql_escape_string($default_code);
    sql_query("
        update " . IEUM_ACADEMY_PROGRAM_TABLE . "
           set is_default = if(program_code = '{$default_sql}', 1, 0),
               updated_at = '" . G5_TIME_YMDHIS . "'
         where academy_id = '{$academy_id}'
    ");
}

function ieum_program_options($academy_id, $active_only = true)
{
    $academy_id = (int) $academy_id;
    ieum_seed_default_programs($academy_id);

    $where = " where academy_id = '{$academy_id}' ";
    if ($active_only) {
        $where .= " and is_active = 1 ";
    }

    $rows = sql_query("
        select *
          from " . IEUM_ACADEMY_PROGRAM_TABLE . "
          {$where}
      order by is_default desc, sort_order asc, program_id asc
    ", false);

    $programs = array();
    while ($row = sql_fetch_array($rows)) {
        $programs[] = $row;
    }

    return $programs;
}

function ieum_program_label($academy_id, $program_code)
{
    $program_code = ieum_program_code($program_code);
    if ($program_code === '') {
        return '';
    }

    $academy_id = (int) $academy_id;
    $code_sql = sql_escape_string($program_code);
    $row = sql_fetch("
        select program_name
          from " . IEUM_ACADEMY_PROGRAM_TABLE . "
         where academy_id = '{$academy_id}'
           and program_code = '{$code_sql}'
         limit 1
    ", false);

    return isset($row['program_name']) ? $row['program_name'] : $program_code;
}
