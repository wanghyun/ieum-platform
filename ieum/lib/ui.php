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
    $items['operations'] = array('label' => '운영 지표', 'url' => IEUM_URL . '/admin/operations.php');
    $items['growth'] = array('label' => '성장 리포트', 'url' => IEUM_URL . '/admin/growth_report.php');
    $items['students'] = array('label' => '학생 관리', 'url' => IEUM_URL . '/admin/students.php');
    $items['groups'] = array('label' => '부별 학생', 'url' => IEUM_URL . '/admin/student_groups.php');
    $items['attendance'] = array('label' => '오늘 출석', 'url' => IEUM_URL . '/admin/attendance_today.php');
    $items['sms'] = array('label' => '문자 큐', 'url' => IEUM_URL . '/admin/sms_queue.php');
    $items['tuition_payments'] = array('label' => '수련비 납부', 'url' => IEUM_URL . '/admin/tuition_payments.php');
    $items['character'] = array('label' => '인성 입력', 'url' => IEUM_URL . '/admin/character.php');
    $items['character_report'] = array('label' => '인성 리포트', 'url' => IEUM_URL . '/admin/character_report.php');
    $items['vehicles'] = array('label' => '차량 관리', 'url' => IEUM_URL . '/admin/vehicles.php');
    $items['boarding'] = array('label' => '탑승 확인', 'url' => IEUM_URL . '/admin/vehicle_boarding.php');
    $items['settings'] = array(
        'label' => '학원 설정',
        'children' => array(
            'classes' => array('label' => '수업 시간표', 'url' => IEUM_URL . '/admin/class_times.php'),
            'calendar' => array('label' => '수업일 설정', 'url' => IEUM_URL . '/admin/school_calendar.php'),
            'tuition' => array('label' => '수련비 정책', 'url' => IEUM_URL . '/admin/tuition.php'),
            'sms_templates' => array('label' => '문자 템플릿', 'url' => IEUM_URL . '/admin/sms_templates.php'),
            'contacts' => array('label' => '알림 담당자', 'url' => IEUM_URL . '/admin/contacts.php'),
        ),
    );

    if ($is_admin === 'super') {
        $items['project'] = array('label' => '진행 현황', 'url' => IEUM_URL . '/project_status.php');
    }

    $items['kiosk'] = array('label' => '태블릿 모드', 'url' => IEUM_URL . '/kiosk.php?tablet=1', 'target' => '_blank');

    return $items;
}

function ieum_admin_header($active = '')
{
    global $is_admin, $member;

    $brand = $is_admin === 'super' ? '아이이음 본사 관리자' : '아이이음 관리자';
    $member_label = isset($member['mb_name']) && $member['mb_name'] !== '' ? $member['mb_name'] : (isset($member['mb_id']) ? $member['mb_id'] : '');
    $html = '<style>.ieum-nav{gap:6px}.nav-group{position:relative;display:inline-flex}.nav-group-title{display:inline-flex;color:#d8e2ff;text-decoration:none;padding:8px 10px;border-radius:6px;cursor:default}.nav-group-title.active,.nav-group:hover .nav-group-title{background:#253469;color:#fff}.nav-sub{display:none;position:absolute;left:0;top:100%;z-index:30;min-width:170px;background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:6px;box-shadow:0 12px 26px rgba(15,23,42,.18)}.nav-group:hover .nav-sub{display:grid;gap:4px}.nav-sub a{color:#111827!important;white-space:nowrap}.nav-sub a:hover,.nav-sub a.active{background:#eef2ff!important;color:#15204a!important}@media(max-width:900px){.nav-group{display:grid}.nav-sub{position:static;margin-top:4px}.nav-group:hover .nav-sub{display:grid}}</style>';
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
            $html .= '<span class="nav-group-title' . ($child_active ? ' active' : '') . '">' . get_text($item['label']) . '</span>';
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
