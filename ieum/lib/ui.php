<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_admin_nav_items()
{
    global $is_admin;

    $items = array();
    if ($is_admin === 'super') {
        $items['academies'] = array('label' => '도장 관리', 'url' => IEUM_URL . '/admin/academies.php');
    }

    $items['dashboard'] = array('label' => '대시보드', 'url' => IEUM_URL . '/dashboard.php');
    $items['operations_group'] = array(
        'label' => '학원 운영',
        'url' => IEUM_URL . '/admin/operations.php',
        'children' => array(
            'operations' => array('label' => '운영 지표', 'url' => IEUM_URL . '/admin/operations.php'),
            'growth' => array('label' => '원생 리포트', 'url' => IEUM_URL . '/admin/growth_report.php'),
        ),
    );
    $items['student_group'] = array(
        'label' => '학생/출석',
        'url' => IEUM_URL . '/admin/students.php',
        'children' => array(
            'students' => array('label' => '학생 관리', 'url' => IEUM_URL . '/admin/students.php'),
            'groups' => array('label' => '부별 학생', 'url' => IEUM_URL . '/admin/student_groups.php'),
            'attendance' => array('label' => '오늘 출석', 'url' => IEUM_URL . '/admin/attendance_today.php'),
            'tablet_devices' => array('label' => '출석기 관리', 'url' => IEUM_URL . '/admin/tablet_devices.php'),
        ),
    );
    $items['billing_group'] = array(
        'label' => '수련비/문자',
        'url' => IEUM_URL . '/admin/tuition_payments.php',
        'children' => array(
            'tuition_payments' => array('label' => '수련비 납부', 'url' => IEUM_URL . '/admin/tuition_payments.php'),
            'sms' => array('label' => '문자 큐', 'url' => IEUM_URL . '/admin/sms_queue.php'),
        ),
    );
    $items['character_group'] = array(
        'label' => '인성 리포트',
        'url' => IEUM_URL . '/admin/character_report.php',
        'children' => array(
            'character' => array('label' => '인성 입력', 'url' => IEUM_URL . '/admin/character.php'),
            'character_mission' => array('label' => '아이잘해 미션', 'url' => IEUM_URL . '/admin/character_mission.php'),
            'character_report' => array('label' => '월간 인성', 'url' => IEUM_URL . '/admin/character_report.php'),
            'character_growth' => array('label' => '인성 성장', 'url' => IEUM_URL . '/admin/character_growth_report.php'),
        ),
    );
    $items['vehicle_group'] = array(
        'label' => '차량',
        'url' => IEUM_URL . '/admin/vehicles.php',
        'children' => array(
            'vehicles' => array('label' => '차량 관리', 'url' => IEUM_URL . '/admin/vehicles.php'),
            'vehicle_assignments' => array('label' => '배정 현황', 'url' => IEUM_URL . '/admin/vehicle_assignments.php'),
            'boarding' => array('label' => '탑승 확인', 'url' => IEUM_URL . '/admin/vehicle_boarding.php'),
        ),
    );
    $items['settings'] = array(
        'label' => '학원 설정',
        'url' => IEUM_URL . '/admin/programs.php',
        'children' => array(
            'programs' => array('label' => '프로그램 설정', 'url' => IEUM_URL . '/admin/programs.php'),
            'classes' => array('label' => '수업 시간표', 'url' => IEUM_URL . '/admin/class_times.php'),
            'calendar' => array('label' => '수업일 설정', 'url' => IEUM_URL . '/admin/school_calendar.php'),
            'tuition' => array('label' => '수련비 정책', 'url' => IEUM_URL . '/admin/tuition.php'),
            'sms_templates' => array('label' => '문자 템플릿', 'url' => IEUM_URL . '/admin/sms_templates.php'),
            'contacts' => array('label' => '알림 담당자', 'url' => IEUM_URL . '/admin/contacts.php'),
            'map_settings' => array('label' => '지도 API 설정', 'url' => IEUM_URL . '/admin/map_settings.php'),
        ),
    );

    if ($is_admin === 'super') {
        $items['billing_wallet'] = array('label' => '청구 발송비', 'url' => IEUM_URL . '/admin/billing_wallet.php');
        $items['project'] = array('label' => '진행 현황', 'url' => IEUM_URL . '/project_status.php');
    }

    $items['kiosk'] = array('label' => '웹 출석기', 'url' => IEUM_URL . '/kiosk.php?tablet=1', 'target' => '_blank');

    return $items;
}

function ieum_admin_header($active = '')
{
    global $is_admin, $member;

    $brand = $is_admin === 'super' ? '아이이음 본사 관리자' : '아이이음 관리자';
    $member_label = isset($member['mb_name']) && $member['mb_name'] !== '' ? $member['mb_name'] : (isset($member['mb_id']) ? $member['mb_id'] : '');
    $html = '<style>.ieum-top{background:#15204a!important;color:#fff!important;padding:14px 24px!important;display:flex!important;align-items:center!important;gap:16px!important;flex-wrap:wrap!important}.ieum-brand{color:#fff!important;text-decoration:none!important;font-size:18px!important;font-weight:900!important}.ieum-nav{display:flex!important;gap:6px!important;flex-wrap:wrap!important;align-items:center!important}.ieum-nav a{color:#d8e2ff!important;text-decoration:none!important;padding:8px 10px!important;border-radius:6px!important}.ieum-nav a.active,.ieum-nav a:hover{background:#253469!important;color:#fff!important}.ieum-user{margin-left:auto!important;color:#cbd5e1!important;font-size:13px!important}.nav-group{position:relative;display:inline-flex}.nav-group-title{display:inline-flex;color:#d8e2ff!important;text-decoration:none;padding:8px 10px;border-radius:6px}.nav-group-title:after{content:"";display:inline-block;width:0;height:0;border-left:4px solid transparent;border-right:4px solid transparent;border-top:5px solid currentColor;margin:8px 0 0 7px;opacity:.8}.nav-group-title.active,.nav-group:hover .nav-group-title,.nav-group-title:focus{background:#253469;color:#fff!important}.nav-sub{display:none;position:absolute;left:0;top:100%;z-index:30;min-width:178px;background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:6px;box-shadow:0 12px 26px rgba(15,23,42,.18)}.nav-group:hover .nav-sub,.nav-group:focus-within .nav-sub{display:grid;gap:4px}.nav-sub a{color:#111827!important;white-space:nowrap}.nav-sub a:hover,.nav-sub a.active{background:#eef2ff!important;color:#15204a!important}@media(max-width:900px){.ieum-user{margin-left:0!important}.nav-group{display:grid}.nav-sub{position:static;margin-top:4px}.nav-group:hover .nav-sub,.nav-group:focus-within .nav-sub{display:grid}}</style>';
    $html .= '<header class="top ieum-top">';
    $html .= '<a class="ieum-brand" href="' . IEUM_URL . '/dashboard.php">' . get_text($brand) . '</a>';
    $html .= '<nav class="ieum-nav">';

    foreach (ieum_admin_nav_items() as $key => $item) {
        if (isset($item['children'])) {
            $child_active = false;
            foreach ($item['children'] as $child_key => $child) {
                if ($active === $child_key) {
                    $child_active = true;
                    break;
                }
            }
            $html .= '<span class="nav-group">';
            $group_url = isset($item['url']) ? $item['url'] : '';
            if ($group_url === '') {
                foreach ($item['children'] as $first_child) {
                    $group_url = $first_child['url'];
                    break;
                }
            }
            $html .= '<a class="nav-group-title' . ($child_active ? ' active' : '') . '" href="' . $group_url . '" aria-haspopup="true">' . get_text($item['label']) . '</a>';
            $html .= '<span class="nav-sub">';
            foreach ($item['children'] as $child_key => $child) {
                $class = $active === $child_key ? ' class="active"' : '';
                $html .= '<a' . $class . ' href="' . $child['url'] . '">' . get_text($child['label']) . '</a>';
            }
            $html .= '</span></span>';
            continue;
        }

        $class = $active === $key ? ' class="active"' : '';
        $target = isset($item['target']) ? ' target="' . get_text($item['target']) . '" rel="noopener"' : '';
        $html .= '<a' . $class . ' href="' . $item['url'] . '"' . $target . '>' . get_text($item['label']) . '</a>';
    }

    $html .= '</nav>';
    if ($member_label !== '') {
        $html .= '<span class="ieum-user">' . get_text($member_label) . '</span>';
    }
    $html .= '</header>';

    return $html;
}

function ieum_admin_subnav($active = '')
{
    $current_group = null;
    foreach (ieum_admin_nav_items() as $item) {
        if (!isset($item['children'])) {
            continue;
        }

        foreach ($item['children'] as $child_key => $child) {
            if ($active === $child_key) {
                $current_group = $item;
                break 2;
            }
        }
    }

    if (!$current_group || !isset($current_group['children'])) {
        return '';
    }

    $html = '<style>.ieum-subnav-wrap{background:#fff;border-bottom:1px solid #d9dee7}.ieum-subnav{max-width:1220px;margin:0 auto;padding:10px 20px;display:flex;gap:8px;align-items:center;overflow-x:auto}.ieum-subnav-title{flex:0 0 auto;color:#475467;font-size:13px;font-weight:900;margin-right:4px}.ieum-subnav a{flex:0 0 auto;display:inline-flex;align-items:center;min-height:34px;padding:7px 12px;border:1px solid #d9dee7;border-radius:999px;background:#f8fafc;color:#344054;text-decoration:none;font-size:14px;font-weight:800;white-space:nowrap}.ieum-subnav a:hover{background:#eef2ff;color:#15204a}.ieum-subnav a.active{background:#1769c2;border-color:#1769c2;color:#fff}@media(max-width:640px){.ieum-subnav{padding:8px 12px}.ieum-subnav-title{display:none}.ieum-subnav a{font-size:13px;padding:7px 10px}}</style>';
    $html .= '<div class="ieum-subnav-wrap"><nav class="ieum-subnav" aria-label="' . get_text($current_group['label']) . ' 하위 메뉴">';
    $html .= '<span class="ieum-subnav-title">' . get_text($current_group['label']) . '</span>';
    foreach ($current_group['children'] as $child_key => $child) {
        $class = $active === $child_key ? ' class="active"' : '';
        $html .= '<a' . $class . ' href="' . $child['url'] . '">' . get_text($child['label']) . '</a>';
    }
    $html .= '</nav></div>';

    return $html;
}
