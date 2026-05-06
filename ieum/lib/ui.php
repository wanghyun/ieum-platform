<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_admin_nav_items()
{
    global $is_admin;

    $items = array(
        'dashboard' => array('label' => '대시보드', 'url' => IEUM_URL . '/dashboard.php'),
        'students' => array('label' => '학생 관리', 'url' => IEUM_URL . '/admin/students.php'),
        'groups' => array('label' => '부별 학생', 'url' => IEUM_URL . '/admin/student_groups.php'),
        'attendance' => array('label' => '오늘 출석', 'url' => IEUM_URL . '/admin/attendance_today.php'),
        'sms' => array('label' => '문자 큐', 'url' => IEUM_URL . '/admin/sms_queue.php'),
        'classes' => array('label' => '수업 시간표', 'url' => IEUM_URL . '/admin/class_times.php'),
        'contacts' => array('label' => '알림 담당자', 'url' => IEUM_URL . '/admin/contacts.php'),
        'tuition' => array('label' => '수련비', 'url' => IEUM_URL . '/admin/tuition.php'),
        'vehicles' => array('label' => '차량 관리', 'url' => IEUM_URL . '/admin/vehicles.php'),
        'boarding' => array('label' => '탑승 확인', 'url' => IEUM_URL . '/admin/vehicle_boarding.php'),
        'project' => array('label' => '진행 현황', 'url' => IEUM_URL . '/project_status.php?token=' . (defined('IEUM_PROJECT_STATUS_TOKEN') ? IEUM_PROJECT_STATUS_TOKEN : '')),
    );

    if ($is_admin === 'super') {
        $items = array('academies' => array('label' => '도장 관리', 'url' => IEUM_URL . '/admin/academies.php')) + $items;
    }

    $items['kiosk'] = array('label' => '태블릿 모드', 'url' => IEUM_URL . '/kiosk.php?tablet=1', 'target' => '_blank');

    return $items;
}

function ieum_admin_header($active = '')
{
    global $is_admin, $member;

    $brand = $is_admin === 'super' ? '아이이음 본사 관리자' : '아이이음 관리자';
    $member_label = isset($member['mb_name']) && $member['mb_name'] !== '' ? $member['mb_name'] : (isset($member['mb_id']) ? $member['mb_id'] : '');
    $html = '<header class="top ieum-top">';
    $html .= '<a class="ieum-brand" href="' . IEUM_URL . '/dashboard.php">' . get_text($brand) . '</a>';
    $html .= '<nav class="ieum-nav">';

    foreach (ieum_admin_nav_items() as $key => $item) {
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
