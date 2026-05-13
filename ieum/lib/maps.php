<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_map_ensure_table()
{
    $engine = defined('G5_DB_ENGINE') && G5_DB_ENGINE ? G5_DB_ENGINE : 'InnoDB';
    $charset = defined('G5_DB_CHARSET') && G5_DB_CHARSET ? G5_DB_CHARSET : 'utf8';

    sql_query("
        create table if not exists " . IEUM_MAP_SETTING_TABLE . " (
            academy_id int unsigned not null,
            provider varchar(30) not null default 'naver',
            use_dynamic_map tinyint(1) not null default 0,
            use_geocoding tinyint(1) not null default 0,
            use_directions tinyint(1) not null default 0,
            naver_client_id varchar(120) not null default '',
            naver_client_secret varchar(160) not null default '',
            naver_web_service_url varchar(255) not null default '',
            memo varchar(255) not null default '',
            updated_at datetime null,
            primary key (academy_id)
        ) engine={$engine} default charset={$charset}
    ", false);
}

function ieum_map_default_settings($academy_id)
{
    return array(
        'academy_id' => (int) $academy_id,
        'provider' => 'naver',
        'use_dynamic_map' => 0,
        'use_geocoding' => 0,
        'use_directions' => 0,
        'naver_client_id' => '',
        'naver_client_secret' => '',
        'naver_web_service_url' => '',
        'memo' => '',
    );
}

function ieum_map_get_settings($academy_id)
{
    $academy_id = (int) $academy_id;
    ieum_map_ensure_table();

    $row = sql_fetch("
        select *
          from " . IEUM_MAP_SETTING_TABLE . "
         where academy_id = '{$academy_id}'
         limit 1
    ", false);

    if (isset($row['academy_id'])) {
        return array_merge(ieum_map_default_settings($academy_id), $row);
    }

    return ieum_map_default_settings($academy_id);
}

function ieum_map_has_api_key($settings)
{
    return trim(isset($settings['naver_client_id']) ? $settings['naver_client_id'] : '') !== '';
}

function ieum_map_mode_label($settings)
{
    if (!ieum_map_has_api_key($settings)) {
        return '지도 링크 방식';
    }

    $enabled = array();
    if (!empty($settings['use_dynamic_map'])) {
        $enabled[] = '지도 표시';
    }
    if (!empty($settings['use_geocoding'])) {
        $enabled[] = '주소 좌표 변환';
    }
    if (!empty($settings['use_directions'])) {
        $enabled[] = '경로 계산';
    }

    return $enabled ? implode(' · ', $enabled) : 'API 키 저장됨';
}
