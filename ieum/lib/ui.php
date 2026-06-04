<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

require_once IEUM_PATH . '/lib/dashboard.php';

function ieum_admin_rail_shortcut_icon($shortcut_key)
{
    $icons = array(
        'attendance_today' => 'check',
        'students' => 'users',
        'tuition_payments' => 'credit-card',
        'vehicle_boarding' => 'bus',
        'character' => 'leaf',
        'character_report' => 'file-text',
        'character_mission' => 'award',
        'monthly_close' => 'check',
        'operations' => 'activity',
        'growth_report' => 'trending-up',
        'vehicle_assignments' => 'map',
        'tablet_devices' => 'phone',
    );

    return isset($icons[$shortcut_key]) ? $icons[$shortcut_key] : 'star';
}

function ieum_admin_nav_icon_svg($icon)
{
    $icons = array(
        'activity' => '<path d="M22 12h-4l-3 8-6-16-3 8H2"/>',
        'award' => '<circle cx="12" cy="8" r="5"/><path d="M8.5 12.5 7 22l5-3 5 3-1.5-9.5"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 7 3 9H3c0-2 3-2 3-9"/><path d="M10 21h4"/>',
        'bus' => '<path d="M6 19v2"/><path d="M18 19v2"/><path d="M4 11h16"/><path d="M5 6h14a2 2 0 0 1 2 2v11H3V8a2 2 0 0 1 2-2Z"/><circle cx="8" cy="15" r="1"/><circle cx="16" cy="15" r="1"/>',
        'calendar' => '<path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 10h18"/><path d="M5 4h14a2 2 0 0 1 2 2v16H3V6a2 2 0 0 1 2-2Z"/>',
        'certificate' => '<path d="M4 4h16v12H4z"/><path d="M8 8h8"/><path d="M8 12h5"/><path d="m15 16 2 4 1-2 2 1-2-4"/>',
        'check' => '<path d="m4 12 5 5L20 6"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'credit-card' => '<path d="M3 7h18v12H3z"/><path d="M3 11h18"/>',
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v18h16V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h6"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1-1.9 1.9-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V20h-2.8v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1-1.9-1.9.1-.1A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.5-1H3v-2.8h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1 1.9-1.9.1.1a1.7 1.7 0 0 0 1.8.3 1.7 1.7 0 0 0 1-1.5V4h2.8v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1 1.9 1.9-.1.1a1.7 1.7 0 0 0-.3 1.8 1.7 1.7 0 0 0 1.5 1h.1v2.8h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
        'home' => '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'leaf' => '<path d="M20 4c-7 0-12 4-12 11 0 3 2 5 5 5 7 0 8-9 8-16Z"/><path d="M8 15c2-2 5-4 9-5"/>',
        'list' => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>',
        'mail' => '<path d="M3 6h18v14H3z"/><path d="m3 7 9 7 9-7"/>',
        'map' => '<path d="m3 6 6-2 6 2 6-2v16l-6 2-6-2-6 2Z"/><path d="M9 4v16"/><path d="M15 6v16"/>',
        'map-pin' => '<path d="M12 21s7-5 7-12a7 7 0 0 0-14 0c0 7 7 12 7 12Z"/><circle cx="12" cy="9" r="2"/>',
        'medal' => '<path d="M8 2h8l-2 5h-4Z"/><circle cx="12" cy="14" r="5"/><path d="m10.5 14 1.2 1.2 2-2.4"/>',
        'message' => '<path d="M4 5h16v11H7l-3 3Z"/>',
        'phone' => '<path d="M7 2h10v20H7z"/><path d="M11 18h2"/>',
        'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'printer' => '<path d="M6 9V3h12v6"/><path d="M6 18H4V9h16v9h-2"/><path d="M7 14h10v7H7z"/>',
        'ruler' => '<path d="M3 17 17 3l4 4L7 21Z"/><path d="m14 6 2 2"/><path d="m11 9 2 2"/><path d="m8 12 2 2"/>',
        'school' => '<path d="m3 10 9-5 9 5-9 5Z"/><path d="M5 12v5c2 2 12 2 14 0v-5"/><path d="M12 15v4"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
        'star' => '<path d="m12 2 3 7 7 .6-5.3 4.7 1.6 6.7-6.3-3.5L5.7 21l1.6-6.7L2 9.6 9 9Z"/>',
        'tag' => '<path d="M20 10 12 2H4v8l8 8Z"/><circle cx="8" cy="6" r="1"/>',
        'trending-up' => '<path d="m3 17 6-6 4 4 8-8"/><path d="M14 7h7v7"/>',
        'upload' => '<path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 20h16"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.8"/><path d="M16 3.2a4 4 0 0 1 0 7.6"/>',
    );

    if (!isset($icons[$icon])) {
        return '';
    }

    return '<svg class="ieum-nav-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $icons[$icon] . '</svg>';
}

function ieum_admin_nav_label_html($item)
{
    $icon = isset($item['icon']) ? ieum_admin_nav_icon_svg($item['icon']) : '';
    return $icon . '<span class="ieum-nav-text">' . get_text($item['label']) . '</span>';
}

function ieum_admin_rail_icon_html($icon)
{
    $map = array(
        '!' => 'bell',
        '✓' => 'check',
        '▥' => 'file-text',
        '◈' => 'award',
        '🔔' => 'bell',
        '✅' => 'check',
        '📊' => 'activity',
        '🎽' => 'award',
        '＋' => 'plus',
    );
    $icon_key = isset($map[$icon]) ? $map[$icon] : $icon;
    $svg = ieum_admin_nav_icon_svg($icon_key);

    return $svg !== '' ? $svg : '<span aria-hidden="true">' . get_text($icon) . '</span>';
}

function ieum_admin_nav_items()
{
    global $is_admin;

    $items = array();
    if ($is_admin === 'super') {
        $items['hq_group'] = array(
            'label' => '본사',
            'url' => IEUM_URL . '/admin/hq_overview.php',
            'children' => array(
                'hq_overview' => array('label' => '본사 현황', 'url' => IEUM_URL . '/admin/hq_overview.php'),
                'academies' => array('label' => '가맹점 관리', 'url' => IEUM_URL . '/admin/academies.php'),
                'paymint_merchants' => array('label' => '결제선생 연동', 'url' => IEUM_URL . '/admin/paymint_merchants.php'),
                'billing_wallet' => array('label' => '결제/청구 운영', 'url' => IEUM_URL . '/admin/billing_wallet.php'),
                'paymint_settings' => array('label' => '결제선생 API', 'url' => IEUM_URL . '/admin/paymint_settings.php'),
                'map_settings' => array('label' => 'API 관리', 'url' => IEUM_URL . '/admin/map_settings.php'),
                'project' => array('label' => '진행 현황', 'url' => IEUM_URL . '/project_status.php'),
            ),
        );

        return $items;
    }

    $items['operations_group'] = array(
        'label' => '학원 운영',
        'icon' => 'school',
        'url' => IEUM_URL . '/admin/operations.php',
        'children' => array(
            'operations' => array('label' => '운영 지표', 'icon' => 'activity', 'url' => IEUM_URL . '/admin/operations.php'),
            'programs' => array('label' => '프로그램 설정', 'icon' => 'tag', 'url' => IEUM_URL . '/admin/programs.php'),
            'classes' => array('label' => '수업 시간표', 'icon' => 'clock', 'url' => IEUM_URL . '/admin/class_times.php'),
            'calendar' => array('label' => '수업일/휴관일', 'icon' => 'calendar', 'url' => IEUM_URL . '/admin/school_calendar.php'),
        ),
    );
    $items['student_group'] = array(
        'label' => '원생/출석',
        'icon' => 'users',
        'url' => IEUM_URL . '/admin/students.php',
        'children' => array(
            'students' => array('label' => '원생 관리', 'icon' => 'users', 'url' => IEUM_URL . '/admin/students.php'),
            'attendance' => array('label' => '오늘 출석', 'icon' => 'check', 'url' => IEUM_URL . '/admin/attendance_today.php'),
            'groups' => array('label' => '부별 명단', 'icon' => 'list', 'url' => IEUM_URL . '/admin/student_groups.php'),
            'student_import' => array('label' => '엑셀 가져오기', 'icon' => 'upload', 'url' => IEUM_URL . '/admin/student_import.php'),
            'tablet_devices' => array('label' => '앱 출석기', 'icon' => 'phone', 'url' => IEUM_URL . '/admin/tablet_devices.php'),
        ),
    );
    $items['billing_group'] = array(
        'label' => '수련비/문자',
        'icon' => 'credit-card',
        'url' => IEUM_URL . '/admin/tuition_payments.php',
        'children' => array(
            'tuition_payments' => array('label' => '수련비 납부', 'icon' => 'credit-card', 'url' => IEUM_URL . '/admin/tuition_payments.php'),
            'family_billing' => array('label' => '형제/자매 청구', 'icon' => 'users', 'url' => IEUM_URL . '/admin/family_billing.php'),
            'sms' => array('label' => '문자 발송현황', 'icon' => 'mail', 'url' => IEUM_URL . '/admin/sms_queue.php'),
            'sms_devices' => array('label' => '문자 발송폰', 'icon' => 'phone', 'url' => IEUM_URL . '/admin/sms_devices.php'),
            'sms_templates' => array('label' => '문자 템플릿', 'icon' => 'message', 'url' => IEUM_URL . '/admin/sms_templates.php'),
            'tuition' => array('label' => '수련비 정책', 'icon' => 'credit-card', 'url' => IEUM_URL . '/admin/tuition.php'),
        ),
    );
    $items['report_group'] = array(
        'label' => '리포트',
        'icon' => 'file-text',
        'url' => IEUM_URL . '/admin/character_report.php',
        'children' => array(
            'monthly_close' => array('label' => '월말 루틴', 'icon' => 'check', 'url' => IEUM_URL . '/admin/monthly_close.php'),
            'growth' => array('label' => '원생 리포트', 'icon' => 'trending-up', 'url' => IEUM_URL . '/admin/growth_report.php'),
            'character' => array('label' => '인성 입력', 'icon' => 'leaf', 'url' => IEUM_URL . '/admin/character.php'),
            'character_mission' => array('label' => '아이잘해 미션', 'icon' => 'award', 'url' => IEUM_URL . '/admin/character_mission.php'),
            'character_report' => array('label' => '월간 인성', 'icon' => 'file-text', 'url' => IEUM_URL . '/admin/character_report.php'),
            'character_growth' => array('label' => '장기 성장', 'icon' => 'trending-up', 'url' => IEUM_URL . '/admin/character_growth_report.php'),
            'fitness' => array('label' => '체력 입력', 'icon' => 'activity', 'url' => IEUM_URL . '/admin/fitness.php'),
            'fitness_reports' => array('label' => '체력 리포트', 'icon' => 'file-text', 'url' => IEUM_URL . '/admin/fitness_reports.php'),
            'fitness_standards' => array('label' => '체력 기준표', 'icon' => 'ruler', 'url' => IEUM_URL . '/admin/fitness_standards.php'),
        ),
    );
    $items['promotion_group'] = array(
        'label' => '승급/심사',
        'icon' => 'medal',
        'url' => IEUM_URL . '/admin/promotion_targets.php',
        'children' => array(
            'promotion_targets' => array('label' => '승급 대상', 'icon' => 'medal', 'url' => IEUM_URL . '/admin/promotion_targets.php'),
            'promotion_poomdan_targets' => array('label' => '승품/단 대상', 'icon' => 'shield', 'url' => IEUM_URL . '/admin/promotion_poomdan_targets.php'),
            'promotion_exam_notices' => array('label' => '심사 안내문', 'icon' => 'mail', 'url' => IEUM_URL . '/admin/promotion_exam_notices.php'),
            'promotion_belts_needed' => array('label' => '준비 띠', 'icon' => 'award', 'url' => IEUM_URL . '/admin/promotion_belts_needed.php'),
            'promotion_certificates' => array('label' => '승급증 인쇄', 'icon' => 'certificate', 'url' => IEUM_URL . '/admin/promotion_certificates.php'),
            'promotion_settings' => array('label' => '승급 설정', 'icon' => 'gear', 'url' => IEUM_URL . '/admin/promotion_settings.php'),
            'promotion_missions' => array('label' => '승급 미션', 'icon' => 'list', 'url' => IEUM_URL . '/admin/promotion_missions.php'),
        ),
    );
    $items['vehicle_group'] = array(
        'label' => '차량',
        'icon' => 'bus',
        'url' => IEUM_URL . '/admin/vehicle_boarding.php',
        'children' => array(
            'boarding' => array('label' => '오늘 탑승', 'icon' => 'check', 'url' => IEUM_URL . '/admin/vehicle_boarding.php'),
            'vehicle_assignments' => array('label' => '차량 배정', 'icon' => 'map', 'url' => IEUM_URL . '/admin/vehicle_assignments.php'),
            'vehicle_monitor' => array('label' => '운행 관리', 'icon' => 'map-pin', 'url' => IEUM_URL . '/admin/vehicle_monitor.php'),
            'vehicle_journal' => array('label' => '차량 일지', 'icon' => 'printer', 'url' => IEUM_URL . '/admin/vehicle_journal.php'),
            'vehicles' => array('label' => '차량/노선 설정', 'icon' => 'bus', 'url' => IEUM_URL . '/admin/vehicles.php'),
        ),
    );

    $settings_children = array(
        'contacts' => array('label' => '알림 담당자', 'icon' => 'bell', 'url' => IEUM_URL . '/admin/contacts.php'),
    );
    $items['settings'] = array(
        'label' => '학원 설정',
        'icon' => 'gear',
        'url' => IEUM_URL . '/admin/contacts.php',
        'children' => $settings_children,
    );
    return $items;
}

function ieum_admin_header($active = '', $layout = 'top')
{
    global $is_admin, $member, $academy, $current_academy, $today, $today_label;

    $shell_academy = (isset($academy) && is_array($academy) && !empty($academy['academy_id']))
        ? $academy
        : ((isset($current_academy) && is_array($current_academy) && !empty($current_academy['academy_id'])) ? $current_academy : array());

    $brand = $is_admin === 'super' ? '아이이음 본사 관리자' : '아이이음 관리자';
    $member_label = isset($member['mb_name']) && $member['mb_name'] !== '' ? $member['mb_name'] : (isset($member['mb_id']) ? $member['mb_id'] : '');
    $brand_url = $is_admin === 'super' ? IEUM_URL . '/admin/hq_overview.php' : IEUM_URL . '/dashboard.php';
    $side_profile_note = '오늘 처리할 일만 먼저 봅니다';

    if ($layout === 'side') {
        $items = ieum_admin_nav_items();
        $html = '<style>
html{scroll-padding-top:92px!important}
.ieum-side-layout{background:#eef1f5!important}
.ieum-side-layout .ieum-side{position:fixed!important;inset:0 auto 0 0!important;width:248px!important;background:#fff!important;color:#0f172a!important;z-index:50!important;display:flex!important;flex-direction:column!important;box-shadow:8px 0 22px rgba(15,23,42,.10)!important;border-right:1px solid #d9dee7!important}
.ieum-side-layout .side-brand{display:flex!important;align-items:center!important;gap:10px!important;min-height:58px!important;padding:0 18px!important;background:#263036!important;color:#fff!important;text-decoration:none!important;font-size:18px!important;font-weight:1000!important}
.ieum-side-layout .side-brand-mark{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:28px!important;height:28px!important;border-radius:8px!important;background:#2450bf!important;color:#fff!important;font-weight:1000!important}
.ieum-side-layout .side-profile{padding:22px 18px 20px!important;text-align:center!important;border-bottom:1px solid #e5eaf1!important}
.ieum-side-layout .side-avatar{display:flex!important;align-items:center!important;justify-content:center!important;width:76px!important;height:76px!important;margin:0 auto 12px!important;border-radius:50%!important;background:#fff!important;color:#2450bf!important;font-size:34px!important;font-weight:1000!important}
.ieum-side-layout .side-profile strong{display:block!important;font-size:17px!important;color:#1f2937!important}
.ieum-side-layout .side-profile span{display:block!important;margin-top:4px!important;color:#64748b!important;font-size:12px!important}
.ieum-side-layout .side-search{padding:12px 16px!important;border-bottom:1px solid #e5eaf1!important}
.ieum-side-layout .side-search input{width:100%!important;height:38px!important;border:0!important;border-radius:7px!important;background:#eef2f7!important;color:#0f172a!important;padding:0 12px!important;font-size:13px!important}
.ieum-side-layout .side-search input::placeholder{color:#94a3b8!important}
.ieum-side-layout .side-nav{padding:10px 0 22px!important;overflow-y:auto!important;scrollbar-width:none!important;-ms-overflow-style:none!important}
.ieum-side-layout .side-nav::-webkit-scrollbar{width:0!important;height:0!important}
.ieum-side-layout .ieum-nav-label{display:inline-flex!important;align-items:center!important;gap:11px!important;min-width:0!important}
.ieum-side-layout .ieum-nav-icon{width:18px!important;height:18px!important;flex:0 0 18px!important;color:currentColor!important}
.ieum-side-layout .ieum-nav-text{display:inline-block!important;min-width:0!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important}
.ieum-side-layout .side-main-link,.ieum-side-layout .side-menu>summary{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:10px!important;min-height:52px!important;padding:0 18px!important;color:#1f2937!important;text-decoration:none!important;font-size:18px!important;font-weight:900!important;cursor:pointer!important;list-style:none!important;border-left:3px solid transparent!important}
.ieum-side-layout .side-menu>summary::-webkit-details-marker{display:none!important}
.ieum-side-layout .side-main-link.active,.ieum-side-layout .side-main-link:hover,.ieum-side-layout .side-menu[open]>summary,.ieum-side-layout .side-menu>summary:hover{background:#edf4ff!important;color:#174ea6!important;border-left-color:#2450bf!important}
.ieum-side-layout .side-menu>summary:after{content:"";display:block;width:7px;height:7px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg);opacity:.72}
.ieum-side-layout .side-menu[open]>summary:after{transform:rotate(225deg);margin-top:5px}
.ieum-side-layout .side-sub{display:grid!important;padding:6px 0 10px!important;background:#f7f9fc!important;border-top:1px solid #e5eaf1!important;border-bottom:1px solid #e5eaf1!important}
.ieum-side-layout .side-sub a{display:flex!important;align-items:center!important;min-height:40px!important;padding:0 18px 0 34px!important;color:#475569!important;text-decoration:none!important;font-size:16px!important;font-weight:800!important}
.ieum-side-layout .side-sub a:hover,.ieum-side-layout .side-sub a.active{background:#eaf1ff!important;color:#174ea6!important}
.ieum-side-layout .side-user{display:none!important}
.ieum-side-layout .wrap{max-width:none!important;margin:0 86px 0 248px!important;padding:26px 28px 42px!important}
.ieum-side-layout .ieum-shell-top{position:fixed!important;top:0!important;left:248px!important;right:74px!important;height:58px!important;background:#263036!important;color:#fff!important;z-index:49!important;display:flex!important;align-items:center!important;justify-content:space-between!important;padding:0 28px!important;border-bottom:1px solid rgba(255,255,255,.08)!important;box-shadow:0 8px 18px rgba(15,23,42,.12)!important}
.ieum-side-layout .ieum-shell-links{display:flex!important;align-items:center!important;gap:10px!important}
.ieum-side-layout .ieum-shell-link{display:inline-flex!important;align-items:center!important;gap:8px!important;min-height:38px!important;border:1px solid rgba(255,255,255,.16)!important;border-radius:10px!important;background:rgba(255,255,255,.08)!important;color:#fff!important;text-decoration:none!important;padding:0 15px!important;font-size:14px!important;font-weight:1000!important}
.ieum-side-layout .ieum-shell-link:hover{background:rgba(255,255,255,.15)!important}
.ieum-side-layout .ieum-shell-link:before{content:"홈";display:inline-flex!important;align-items:center!important;justify-content:center!important;width:24px!important;height:24px!important;border-radius:7px!important;background:#2450bf!important;color:#fff!important;font-size:12px!important}
.ieum-side-layout .ieum-shell-meta{color:#cbd5e1!important;font-size:13px!important;font-weight:800!important}
.ieum-side-layout .ieum-shell-top~.wrap{padding-top:84px!important}
.ieum-side-layout .ieum-main{max-width:none!important;margin:0 86px 0 248px!important;padding:84px 28px 42px!important}
.ieum-side-layout :target{scroll-margin-top:92px!important}
.ieum-side-layout .ieum-right-rail{position:fixed!important;right:0!important;top:58px!important;bottom:0!important;width:74px!important;background:#fff!important;border-left:1px solid #d9dee7!important;box-shadow:-8px 0 18px rgba(15,23,42,.06)!important;z-index:48!important;display:flex!important;flex-direction:column!important;align-items:center!important;padding:14px 6px!important;gap:10px!important}
.ieum-side-layout .ieum-right-rail,.ieum-side-layout .ieum-right-rail *{box-sizing:border-box!important}
.ieum-side-layout .ieum-rail-link{position:relative!important;display:flex!important;flex-direction:column!important;align-items:center!important;gap:5px!important;width:62px!important;min-height:72px!important;border-radius:16px!important;color:#344054!important;text-decoration:none!important;padding:8px 4px!important;font-size:12px!important;font-weight:1000!important}
.ieum-side-layout .ieum-rail-link:hover,.ieum-side-layout .ieum-rail-link.active{background:#f3f7fd!important;color:#1769c2!important}
.ieum-side-layout .ieum-rail-icon{display:flex!important;align-items:center!important;justify-content:center!important;width:38px!important;height:38px!important;border-radius:50%!important;background:#eef5ff!important;font-size:23px!important;line-height:1!important}
.ieum-side-layout .ieum-rail-icon .ieum-nav-icon{width:22px!important;height:22px!important;flex:0 0 22px!important;color:currentColor!important}
.ieum-side-layout .ieum-rail-link.warn .ieum-rail-icon{background:#fff4df!important}
.ieum-side-layout .ieum-rail-link.danger .ieum-rail-icon{background:#fff1f2!important}
.ieum-side-layout .ieum-rail-link.plus .ieum-rail-icon{color:#1769c2!important;font-size:26px!important;font-weight:1000!important}
.ieum-side-layout .ieum-rail-link.favorite .ieum-rail-icon{background:#f5f3ff!important;color:#4f46e5!important}
.ieum-side-layout .ieum-rail-link.favorite span:last-child{font-size:11px!important;line-height:1.16!important;text-align:center!important;word-break:keep-all!important}
.ieum-side-layout .ieum-rail-link.plus{border:1px dashed #b7c7df!important;background:#fbfdff!important}
.ieum-side-layout .ieum-rail-badge{position:absolute!important;right:6px!important;top:4px!important;min-width:20px!important;height:20px!important;border-radius:999px!important;background:#ef4444!important;color:#fff!important;font-style:normal!important;font-size:11px!important;font-weight:1000!important;display:flex!important;align-items:center!important;justify-content:center!important;padding:0 5px!important;line-height:1!important}
.ieum-side-layout .ieum-rail-link.ok .ieum-rail-badge{background:#16a34a!important}
.ieum-side-layout .ieum-rail-link.warn .ieum-rail-badge{background:#f59e0b!important}
.ieum-side-layout .ieum-rail-link.danger .ieum-rail-badge{background:#ef4444!important}
/* Common shell final pass: keep every admin page inside the same calm fixed frame. */
.ieum-side-layout{--ieum-side-width:248px;--ieum-rail-width:74px;--ieum-top-height:58px;--ieum-shell-top:#263036;overflow-x:hidden!important}
.ieum-side-layout body{overflow-x:hidden!important}
.ieum-side-layout .ieum-side{background:#fff!important;color:#0f172a!important;border-right:1px solid #d9dee7!important;box-shadow:8px 0 22px rgba(15,23,42,.08)!important}
.ieum-side-layout .side-brand{height:var(--ieum-top-height)!important;min-height:var(--ieum-top-height)!important;background:var(--ieum-shell-top)!important}
.ieum-side-layout .side-profile{background:#fff!important;border-bottom:1px solid #e5eaf1!important}
.ieum-side-layout .side-avatar{background:#f8fafc!important;border:1px solid #d9e2f0!important;color:#2450bf!important}
.ieum-side-layout .side-profile strong{color:#1f2937!important}
.ieum-side-layout .side-profile span{color:#64748b!important}
.ieum-side-layout .side-search{background:#fff!important;border-bottom:1px solid #e5eaf1!important}
.ieum-side-layout .side-main-link,.ieum-side-layout .side-menu>summary{color:#1f2937!important}
.ieum-side-layout .side-main-link.active,.ieum-side-layout .side-main-link:hover,.ieum-side-layout .side-menu[open]>summary,.ieum-side-layout .side-menu>summary:hover{background:#edf4ff!important;color:#174ea6!important}
.ieum-side-layout .side-sub{background:#f7f9fc!important}
.ieum-side-layout .side-sub a{color:#475569!important}
.ieum-side-layout .side-sub a:hover,.ieum-side-layout .side-sub a.active{background:#eaf1ff!important;color:#174ea6!important}
.ieum-side-layout .ieum-shell-top{left:var(--ieum-side-width)!important;right:var(--ieum-rail-width)!important;height:var(--ieum-top-height)!important;background:var(--ieum-shell-top)!important;z-index:62!important}
.ieum-side-layout .wrap,.ieum-side-layout .ieum-main{max-width:none!important;margin:0 var(--ieum-rail-width) 0 var(--ieum-side-width)!important;padding:84px 28px 42px!important}
.ieum-side-layout .ieum-right-rail{top:0!important;right:0!important;width:var(--ieum-rail-width)!important;background:#fff!important;z-index:64!important;padding-top:72px!important}
.ieum-side-layout [id]{scroll-margin-top:104px!important}
.ieum-side-layout .ieum-shell-menu,.ieum-side-layout .ieum-shell-quick,.ieum-side-layout .ieum-shell-backdrop{display:none!important}
body.ieum-side-layout.ieum-dashboard-page{--ieum-side-width:260px!important;--ieum-rail-width:0px!important;--ieum-top-height:64px!important;--ieum-shell-top:#fff!important;background:#f5f7fb!important;color:#111827!important}
body.ieum-side-layout.ieum-dashboard-page .ieum-side{width:260px!important;box-sizing:border-box!important;background:#fff!important;border-right:1px solid #e5e7eb!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page .side-brand{height:144px!important;min-height:144px!important;padding:0 22px!important;align-items:center!important;background:#fff!important;color:#111827!important;font-size:28px!important;font-weight:900!important;letter-spacing:0!important;border-bottom:0!important;box-sizing:border-box!important;line-height:1.12!important;word-break:keep-all!important}
body.ieum-side-layout.ieum-dashboard-page .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page .side-profile,
body.ieum-side-layout.ieum-dashboard-page .side-search,
body.ieum-side-layout.ieum-dashboard-page .ieum-right-rail{display:none!important}
body.ieum-side-layout.ieum-dashboard-page .side-nav{padding:0 14px 22px!important}
body.ieum-side-layout.ieum-dashboard-page .side-main-link,
body.ieum-side-layout.ieum-dashboard-page .side-menu>summary{min-height:42px!important;padding:0 12px!important;border-left:0!important;border-radius:6px!important;font-size:14px!important;font-weight:700!important;color:#243142!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page .side-main-link.active,
body.ieum-side-layout.ieum-dashboard-page .side-main-link:hover,
body.ieum-side-layout.ieum-dashboard-page .side-menu[open]>summary,
body.ieum-side-layout.ieum-dashboard-page .side-menu>summary:hover{background:#f4f7fb!important;color:#111827!important}
body.ieum-side-layout.ieum-dashboard-page .ieum-nav-label{gap:8px!important}
body.ieum-side-layout.ieum-dashboard-page .ieum-nav-icon{width:17px!important;height:17px!important;flex:0 0 17px!important;color:#334155!important}
body.ieum-side-layout.ieum-dashboard-page .side-sub{background:#fff!important;border:0!important;padding:2px 0 8px!important}
body.ieum-side-layout.ieum-dashboard-page .side-sub a{min-height:32px!important;padding:0 12px 0 34px!important;font-size:13px!important;font-weight:600!important;color:#4b5563!important}
body.ieum-side-layout.ieum-dashboard-page .side-sub a:hover,
body.ieum-side-layout.ieum-dashboard-page .side-sub a.active{background:#f1f5f9!important;color:#111827!important}
body.ieum-side-layout.ieum-dashboard-page .ieum-shell-top{left:260px!important;right:0!important;height:64px!important;background:#fff!important;border-bottom:1px solid #eef2f7!important;color:#0f172a!important;box-shadow:none!important;z-index:62!important}
body.ieum-side-layout.ieum-dashboard-page .ieum-shell-link,
body.ieum-side-layout.ieum-dashboard-page .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}
body.ieum-side-layout.ieum-dashboard-page .ieum-shell-link:before{display:none!important}
body.ieum-side-layout.ieum-dashboard-page .ieum-shell-meta{color:#0f172a!important}
body.ieum-side-layout.ieum-dashboard-page .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
body.ieum-side-layout.ieum-dashboard-page .dashboard-shell-divider,
body.ieum-side-layout.ieum-dashboard-page .dashboard-shell-help-dot{color:#94a3b8!important}
body.ieum-side-layout.ieum-dashboard-page .wrap,
body.ieum-side-layout.ieum-dashboard-page .ieum-main{max-width:none!important;width:auto!important;margin:0 0 0 260px!important;padding:96px 40px 42px!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page{--ieum-side-width:260px!important;--ieum-rail-width:0px!important;--ieum-top-height:64px!important;--ieum-shell-top:#fff!important;background:#f5f7fb!important;color:#111827!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-side{width:260px!important;background:#fff!important;border-right:1px solid #e5e7eb!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-brand{height:144px!important;min-height:144px!important;padding:0 22px!important;background:#fff!important;color:#111827!important;font-size:28px!important;font-weight:900!important;letter-spacing:0!important;line-height:1.12!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-profile,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-search,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-right-rail{display:none!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-nav{padding:0 14px 24px!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-main-link,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-menu>summary{min-height:42px!important;padding:0 12px!important;border-radius:6px!important;border-left:0!important;color:#0f172a!important;font-size:15px!important;font-weight:900!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-main-link:hover,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-main-link.active,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-menu[open]>summary,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-menu>summary:hover{background:#f1f5f9!important;color:#0f172a!important;border-left:0!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-nav-label{gap:10px!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-nav-icon{width:18px!important;height:18px!important;color:#334155!important;opacity:1!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-sub{margin:2px 0 8px!important;padding:0 0 0 28px!important;background:transparent!important;border:0!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-sub a{min-height:34px!important;padding:0 10px!important;border-radius:6px!important;color:#475569!important;font-size:14px!important;font-weight:800!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-sub a:hover,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-sub a.active{background:#f1f5f9!important;color:#0f172a!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-shell-top{left:260px!important;right:0!important;height:64px!important;background:#fff!important;border-bottom:1px solid #eef2f7!important;color:#0f172a!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-shell-link,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-shell-link:before{display:none!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-shell-meta{color:#0f172a!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .dashboard-shell-meta-inner{display:flex!important;align-items:center!important;justify-content:flex-end!important;gap:8px!important;white-space:nowrap!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .dashboard-shell-divider,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .dashboard-shell-help-dot{color:#94a3b8!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .wrap,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-main{max-width:none!important;width:auto!important;margin:0 0 0 260px!important;padding:96px 40px 42px!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .hero,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .bar{padding:0!important;margin:0 0 14px!important;border:0!important;background:transparent!important;border-radius:0!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .hero h1,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .bar h1{margin:0!important;font-size:30px!important;font-weight:1000!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .meta{margin-top:6px!important;color:#64748b!important;font-size:14px!important;line-height:1.45!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .panel,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .report-card,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .student-summary,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .card,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .brief-card,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .stat-card,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .signal,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .class-chip,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .student-side,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .student-list-panel,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .missing-item,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .vehicle-note,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .paymint-gate,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .preview-box,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .guide-card{border:1px solid #dfe5ee!important;border-radius:8px!important;background:#fff!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .panel,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .report-card,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .student-summary{padding:18px!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .summary-card,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .chip,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .quick-note,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ready-step,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .step,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .install-step,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .routine-box,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .report-kpi,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .filters,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .table-scroll,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .table-wrap,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .queue-table-wrap,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .download-url,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .pill{border-radius:8px!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-group,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .side-link,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .student-card,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .student-table-wrap,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .detail-field,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .detail-section,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .preview-item,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .preview-detail,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .billing-settings,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .form-guide,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .form-jump-bar,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .form-summary-card{border-radius:8px!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .btn,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page input,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page select,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page textarea{border-radius:6px!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .btn{min-height:38px!important;font-size:14px!important;font-weight:900!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .brief-card.primary-brief{background:#fff!important;color:#0f172a!important;border-color:#dfe5ee!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .primary-brief .brief-title,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .primary-brief .brief-sub{color:#64748b!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .primary-brief .brief-meter{background:#e7edf7!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .primary-brief .brief-meter span{background:#1769c2!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page h2{letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .help,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .meta,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .muted{word-break:keep-all!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page table{background:#fff!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page th{background:#f3f6fb!important;color:#334155!important;border-color:#e2e8f0!important;font-size:13px!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page td{border-color:#e5ebf3!important;font-size:13px!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .notice{border-radius:8px!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .wrap,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-main{padding:88px 40px 38px!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .hero,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .bar{margin-bottom:12px!important;gap:12px!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .hero h1,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .bar h1,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .head h1,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .wrap>h1,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-main>h1{font-size:28px!important;line-height:1.18!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .wrap>h1,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-main>h1{margin:0 0 8px!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .wrap>h1+.meta,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-main>h1+.meta{margin-bottom:14px!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page h2,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .panel h2,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .student-list-head h2{margin:0 0 12px!important;font-size:18px!important;line-height:1.28!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .panel,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .report-card,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .student-summary,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .student-list-panel{padding:16px!important;margin-bottom:14px!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .summary-card,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .stat-card,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .card,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .kpi,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .report-kpi,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .setting-card,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .routine-item,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ready-step,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .install-step{padding:14px!important;border-radius:8px!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .filters,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .search,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .bulk,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .send-bar,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .report-send-bar,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .list-page-bar{gap:8px!important;border-radius:8px!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .btn{min-height:36px!important;padding:7px 11px!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page input,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page select,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page textarea{min-height:36px!important;padding:7px 10px!important;font-size:14px!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page th,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page td{padding:8px 9px!important;line-height:1.42!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page tbody tr:hover td{background:#fbfdff!important}
@media(max-width:980px){
.ieum-side-layout{--ieum-side-width:292px;--ieum-rail-width:292px;overflow-x:hidden!important}
body.ieum-side-layout.ieum-dashboard-page .ieum-shell-top,.ieum-side-layout .ieum-shell-top{position:fixed!important;left:0!important;right:0!important;top:0!important;width:100%!important;height:58px!important;padding:0 10px!important;gap:8px!important;z-index:86!important}
.ieum-side-layout .ieum-shell-links{min-width:0!important;flex:1!important}
.ieum-side-layout .ieum-shell-link{min-width:0!important;max-width:100%!important;padding:0 11px!important;font-size:13px!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important}
.ieum-side-layout .ieum-shell-links>a.ieum-shell-link{max-width:118px!important;font-size:0!important}
.ieum-side-layout .ieum-shell-links>a.ieum-shell-link:after{content:"홈페이지"!important;display:inline!important;font-size:13px!important;color:#fff!important;background:transparent!important;padding:0!important}
.ieum-side-layout .ieum-shell-link:before{width:22px!important;height:22px!important;flex:0 0 auto!important}
.ieum-side-layout .ieum-shell-meta{display:none!important}
.ieum-side-layout .ieum-shell-menu,.ieum-side-layout .ieum-shell-quick{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:42px!important;height:42px!important;border:1px solid rgba(255,255,255,.18)!important;border-radius:12px!important;background:rgba(255,255,255,.10)!important;color:#fff!important;font-size:20px!important;font-weight:1000!important;cursor:pointer!important;flex:0 0 auto!important}
.ieum-side-layout .ieum-shell-quick{font-size:18px!important}
.ieum-side-layout .ieum-shell-backdrop{position:fixed!important;inset:0!important;background:rgba(15,23,42,.42)!important;z-index:82!important}
.ieum-side-layout.ieum-side-open .ieum-shell-backdrop,.ieum-side-layout.ieum-rail-open .ieum-shell-backdrop{display:block!important}
.ieum-side-layout .ieum-side{position:fixed!important;top:58px!important;bottom:0!important;left:0!important;width:min(84vw,292px)!important;transform:translateX(-104%)!important;transition:transform .22s ease!important;z-index:84!important;box-shadow:18px 0 40px rgba(15,23,42,.24)!important}
.ieum-side-layout.ieum-side-open .ieum-side{transform:translateX(0)!important}
.ieum-side-layout .side-brand{display:none!important}
.ieum-side-layout .side-profile{display:block!important;padding:18px 16px 14px!important}
.ieum-side-layout .side-avatar{width:60px!important;height:60px!important;font-size:28px!important;margin-bottom:10px!important}
.ieum-side-layout .side-search{display:block!important;padding:10px 14px!important}
.ieum-side-layout .side-nav{display:block!important;padding:8px 0 24px!important;overflow-y:auto!important}
.ieum-side-layout .side-menu>summary,.ieum-side-layout .side-main-link{min-height:48px!important;border-radius:0!important;border-left:3px solid transparent!important;font-size:17px!important}
.ieum-side-layout .side-sub{border-radius:0!important}
.ieum-side-layout .side-sub a{font-size:15px!important;min-height:38px!important}
.ieum-side-layout .wrap,.ieum-side-layout .ieum-main{margin:0!important;padding:76px 12px 34px!important;width:100%!important;max-width:none!important}
.ieum-side-layout .ieum-shell-top~.wrap{padding-top:76px!important}
body.ieum-side-layout.ieum-dashboard-page .ieum-right-rail,.ieum-side-layout .ieum-right-rail{position:fixed!important;top:58px!important;right:auto!important;left:100%!important;bottom:0!important;width:min(84vw,292px)!important;transform:none!important;transition:left .22s ease!important;z-index:84!important;background:#fff!important;border-left:1px solid #d9dee7!important;box-shadow:-18px 0 40px rgba(15,23,42,.20)!important;padding:14px!important;display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;align-content:start!important;gap:10px!important;overflow-y:auto!important}
body.ieum-side-layout:not(.ieum-rail-open) .ieum-right-rail{display:none!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-rail-open .ieum-right-rail,.ieum-side-layout.ieum-rail-open .ieum-right-rail{left:calc(100% - min(84vw,292px))!important;transform:none!important}
.ieum-side-layout .ieum-rail-link{width:100%!important;min-height:82px!important;border:1px solid #e1e7f0!important;background:#fbfdff!important}
.ieum-side-layout .ieum-rail-link.plus{grid-column:1/-1!important;min-height:56px!important;flex-direction:row!important}
.ieum-side-layout [id]{scroll-margin-top:72px!important}
html,
body.ieum-side-layout{max-width:100%!important;overflow-x:hidden!important}
body.ieum-side-layout.ieum-dashboard-page .ieum-shell-top,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-shell-top,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .ieum-shell-top{
    left:0!important;
    right:0!important;
    width:100%!important;
    max-width:100vw!important;
    min-width:0!important;
    box-sizing:border-box!important;
    overflow:hidden!important;
}
body.ieum-side-layout.ieum-dashboard-page .ieum-shell-link,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-shell-link,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .ieum-shell-link{
    background:#fff!important;
    border:1px solid #cbd5e1!important;
    border-radius:10px!important;
    color:#0f172a!important;
}
body.ieum-side-layout.ieum-dashboard-page .ieum-shell-links>a.ieum-shell-link:after,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-shell-links>a.ieum-shell-link:after,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .ieum-shell-links>a.ieum-shell-link:after{
    color:#0f172a!important;
}
body.ieum-side-layout.ieum-dashboard-page .ieum-shell-menu,
body.ieum-side-layout.ieum-dashboard-page .ieum-shell-quick,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-shell-menu,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-shell-quick,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .ieum-shell-menu,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .ieum-shell-quick{
    background:#fff!important;
    border-color:#cbd5e1!important;
    color:#0f172a!important;
    box-shadow:0 6px 16px rgba(15,23,42,.10)!important;
}
body.ieum-side-layout.ieum-dashboard-page .wrap,
body.ieum-side-layout.ieum-dashboard-page .ieum-main,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .wrap,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-main,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .wrap,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .ieum-main{
    margin:0!important;
    width:100%!important;
    max-width:100%!important;
    min-width:0!important;
    padding:76px 12px 34px!important;
    box-sizing:border-box!important;
}
body.ieum-side-layout.ieum-dashboard-page .wrap>*,
body.ieum-side-layout.ieum-dashboard-page .ieum-main>*,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .wrap>*,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .ieum-main>*,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .wrap>*,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .ieum-main>*{
    max-width:100%!important;
    min-width:0!important;
    box-sizing:border-box!important;
}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .panel,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .report-card,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .student-summary,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .student-list-panel,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .card,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .panel,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .report-card,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .student-summary,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .student-list-panel,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .card{
    width:100%!important;
    max-width:100%!important;
}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .grid,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .cards,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .summary,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .student-workspace,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .student-flow-grid,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .student-list-head,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .schedule-board,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .grid,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .cards,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .summary,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .student-workspace,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .student-flow-grid,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .student-list-head,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .schedule-board{
    grid-template-columns:1fr!important;
}
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .table-wrap,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .table-scroll,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .queue-table-wrap,
body.ieum-side-layout.ieum-dashboard-page.ieum-simple-page .student-table-wrap,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .table-wrap,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .table-scroll,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .queue-table-wrap,
body.ieum-side-layout.ieum-dashboard-page[class*="-page-tune"][class] .student-table-wrap{
    width:100%!important;
    max-width:100%!important;
    overflow-x:auto!important;
    -webkit-overflow-scrolling:touch!important;
}
}
body.ieum-side-layout.ieum-dashboard-page.ieum-dark{background:#0f1724!important;color:#d9e2ef!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .ieum-shell-top,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .ieum-side,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .side-brand{background:#111827!important;border-color:#263244!important;color:#e5edf7!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .side-profile,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .side-search,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .side-sub{background:#111827!important;border-color:#263244!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .side-profile strong,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .side-main-link,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .side-menu>summary,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .ieum-nav-icon,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .ieum-shell-link,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .dashboard-shell-support-link,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .dashboard-shell-clock{color:#e5edf7!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .side-profile span,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .side-sub a,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .ieum-shell-meta{color:#9aa8bb!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .side-main-link.active,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .side-main-link:hover,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .side-menu[open]>summary,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .side-menu>summary:hover,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .side-sub a:hover,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .side-sub a.active{background:#1a2434!important;color:#f8fafc!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .ieum-shell-link{background:transparent!important;border-color:transparent!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .ieum-shell-links>a.ieum-shell-link:after{color:#e5edf7!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .ieum-shell-menu,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .ieum-shell-quick,
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .dashboard-theme-toggle{background:#172132!important;border-color:#334155!important;color:#f8fafc!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .ieum-right-rail{background:#111827!important;border-color:#263244!important}
body.ieum-side-layout.ieum-dashboard-page.ieum-dark .ieum-rail-link{background:#151f2e!important;border-color:#2c3a4f!important;color:#e5edf7!important}
</style>';
        $html .= '<aside class="ieum-side" aria-label="아이이음 관리자 메뉴">';
        $html .= '<a class="side-brand" href="' . $brand_url . '"><span class="side-brand-mark">I</span><span>' . get_text($brand) . '</span></a>';
        $html .= '<div class="side-profile"><div class="side-avatar">도</div><strong>' . get_text($member_label ?: '관리자') . '</strong><span>' . get_text($side_profile_note) . '</span></div>';
        $html .= '<div class="side-search"><input type="search" placeholder="메뉴 검색"></div>';
        $html .= '<nav class="side-nav">';
        $html .= '<a class="side-main-link' . ($active === 'dashboard' ? ' active' : '') . '" href="' . IEUM_URL . '/dashboard.php"><span class="ieum-nav-label">' . ieum_admin_nav_label_html(array('label' => '메인', 'icon' => 'home')) . '</span></a>';
        foreach ($items as $key => $item) {
            if (!isset($item['children'])) {
                $class = $active === $key ? ' active' : '';
                $target = isset($item['target']) ? ' target="' . get_text($item['target']) . '" rel="noopener"' : '';
                $html .= '<a class="side-main-link' . $class . '" href="' . $item['url'] . '"' . $target . '><span class="ieum-nav-label">' . ieum_admin_nav_label_html($item) . '</span></a>';
                continue;
            }

            $child_active = false;
            foreach ($item['children'] as $child_key => $child) {
                if ($active === $child_key) {
                    $child_active = true;
                    break;
                }
            }
            $html .= '<details class="side-menu" ' . ($child_active ? 'open' : '') . '>';
            $html .= '<summary><span class="ieum-nav-label">' . ieum_admin_nav_label_html($item) . '</span></summary>';
            $html .= '<div class="side-sub">';
            foreach ($item['children'] as $child_key => $child) {
                $class = $active === $child_key ? ' class="active"' : '';
                $html .= '<a' . $class . ' href="' . $child['url'] . '"><span class="ieum-nav-label">' . ieum_admin_nav_label_html($child) . '</span></a>';
            }
            $html .= '</div></details>';
        }
        $html .= '</nav>';
        $html .= '</aside>';
        if ($layout === 'side') {
            $shell_meta = '';
            $shell_today = !empty($today) ? $today : date('Y-m-d');
            $shell_today_label = isset($today_label) && $today_label !== '' ? $today_label : '';
            if ($shell_today_label === '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $shell_today)) {
                $shell_week_labels = array('일', '월', '화', '수', '목', '금', '토');
                $shell_today_label = $shell_week_labels[(int) date('w', strtotime($shell_today))];
            }
            if (!empty($shell_academy['academy_code'])) {
                $shell_meta = $shell_academy['academy_code'];
                if (!empty($shell_today)) {
                    $shell_meta .= ' · ' . $shell_today;
                    if ($shell_today_label !== '') {
                        $shell_meta .= ' ' . $shell_today_label . '요일';
                    }
                }
            }
            $html .= '<header class="ieum-shell-top" aria-label="아이이음 바로가기">';
            $html .= '<div class="ieum-shell-links"><a class="ieum-shell-link" href="https://wooacha.com" target="_blank" rel="noopener">아이이음 홈페이지</a></div>';
            $html .= '<div class="ieum-shell-meta">' . get_text($shell_meta) . '</div>';
            $html .= '</header>';
            $html .= '<aside class="ieum-right-rail" aria-label="빠른 확인">';
            $rail_summary = array();
            if (!empty($shell_academy['academy_id']) && function_exists('ieum_dashboard_rail_summary')) {
                $rail_summary = ieum_dashboard_rail_summary($shell_academy, isset($today) ? $today : '');
            }
            if (!$rail_summary) {
                $rail_summary = array(
                    array('label' => '알림', 'icon' => 'bell', 'count' => 0, 'tone' => 'ok', 'url' => IEUM_URL . '/dashboard.php#autoCheck'),
                    array('label' => '할 일', 'icon' => 'check', 'count' => 0, 'tone' => 'ok', 'url' => IEUM_URL . '/dashboard.php#todayTodo'),
                    array('label' => '리포트', 'icon' => 'file-text', 'count' => 0, 'tone' => 'ok', 'url' => IEUM_URL . '/dashboard.php#reportClose'),
                    array('label' => '준비', 'icon' => 'award', 'count' => 0, 'tone' => 'ok', 'url' => IEUM_URL . '/admin/promotion_belts_needed.php'),
                );
            }
            foreach ($rail_summary as $rail_item) {
                $rail_tone = !empty($rail_item['tone']) ? preg_replace('/[^a-z0-9_-]/i', '', (string) $rail_item['tone']) : '';
                $rail_count = isset($rail_item['count']) ? (int) $rail_item['count'] : 0;
                $rail_badge = $rail_count > 0 ? '<em class="ieum-rail-badge">' . number_format($rail_count) . '</em>' : '';
                $html .= '<a class="ieum-rail-link ' . $rail_tone . '" href="' . get_text($rail_item['url']) . '"><span class="ieum-rail-icon">' . ieum_admin_rail_icon_html($rail_item['icon']) . '</span><span>' . get_text($rail_item['label']) . '</span>' . $rail_badge . '</a>';
            }
            $rail_shortcuts = array();
            if (!empty($shell_academy['academy_id']) && function_exists('ieum_dashboard_get_shortcut_keys') && function_exists('ieum_dashboard_resolve_shortcuts')) {
                $rail_shortcut_keys = array_slice(ieum_dashboard_get_shortcut_keys((int) $shell_academy['academy_id']), 0, 4);
                $rail_shortcuts = ieum_dashboard_resolve_shortcuts($rail_shortcut_keys);
            }
            foreach ($rail_shortcuts as $rail_shortcut_key => $rail_shortcut) {
                $html .= '<a class="ieum-rail-link favorite" href="' . get_text($rail_shortcut['url']) . '"><span class="ieum-rail-icon">' . ieum_admin_rail_icon_html(ieum_admin_rail_shortcut_icon($rail_shortcut_key)) . '</span><span>' . get_text($rail_shortcut['label']) . '</span></a>';
            }
            $html .= '<a class="ieum-rail-link plus" href="' . IEUM_URL . '/dashboard.php#favoriteSettings"><span class="ieum-rail-icon">' . ieum_admin_rail_icon_html('plus') . '</span><span>추가</span></a>';
            $html .= '</aside>';
            $html .= '<script>(function(){function ready(fn){if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",fn);}else{fn();}}function closeShell(){document.body.classList.remove("ieum-side-open","ieum-rail-open");var m=document.querySelector(".ieum-shell-menu");var q=document.querySelector(".ieum-shell-quick");if(m){m.setAttribute("aria-expanded","false");}if(q){q.setAttribute("aria-expanded","false");}}ready(function(){var top=document.querySelector(".ieum-shell-top");if(top&&!document.querySelector(".ieum-shell-menu")){var menu=document.createElement("button");menu.type="button";menu.className="ieum-shell-menu";menu.setAttribute("aria-label","메뉴 열기");menu.setAttribute("aria-expanded","false");menu.textContent="☰";top.insertBefore(menu,top.firstChild);var quick=document.createElement("button");quick.type="button";quick.className="ieum-shell-quick";quick.setAttribute("aria-label","빠른 확인 열기");quick.setAttribute("aria-expanded","false");quick.textContent="⋯";top.appendChild(quick);}if(!document.querySelector(".ieum-shell-backdrop")){var backdrop=document.createElement("div");backdrop.className="ieum-shell-backdrop";backdrop.setAttribute("aria-hidden","true");document.body.insertBefore(backdrop,document.body.firstChild);}});document.addEventListener("click",function(e){var menu=e.target.closest&&e.target.closest(".ieum-shell-menu");var quick=e.target.closest&&e.target.closest(".ieum-shell-quick");var backdrop=e.target.closest&&e.target.closest(".ieum-shell-backdrop");var plus=e.target.closest&&e.target.closest(".ieum-rail-link.plus");if(menu){e.preventDefault();var open=!document.body.classList.contains("ieum-side-open");document.body.classList.toggle("ieum-side-open",open);document.body.classList.remove("ieum-rail-open");menu.setAttribute("aria-expanded",open?"true":"false");var q=document.querySelector(".ieum-shell-quick");if(q){q.setAttribute("aria-expanded","false");}return;}if(quick){e.preventDefault();var qopen=!document.body.classList.contains("ieum-rail-open");document.body.classList.toggle("ieum-rail-open",qopen);document.body.classList.remove("ieum-side-open");quick.setAttribute("aria-expanded",qopen?"true":"false");var m=document.querySelector(".ieum-shell-menu");if(m){m.setAttribute("aria-expanded","false");}return;}if(backdrop){closeShell();return;}if(plus){var panel=document.getElementById("favoriteSettings");if(panel){e.preventDefault();panel.open=!panel.open;closeShell();setTimeout(function(){panel.scrollIntoView({block:"start",behavior:"smooth"});},30);}}});document.addEventListener("keydown",function(e){if(e.key==="Escape"){closeShell();}});})();</script>';
            $html .= '<script>(function(){function fixHashOffset(){if(!location.hash){return;}var el=document.getElementById(decodeURIComponent(location.hash.slice(1)));if(!el){return;}setTimeout(function(){var top=el.getBoundingClientRect().top+window.pageYOffset-104;window.scrollTo({top:Math.max(0,top),behavior:"auto"});},40);}window.addEventListener("hashchange",fixHashOffset);if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",fixHashOffset);}else{fixHashOffset();}})();</script>';
        }

        return $html;
    }

    $html = '<style>
.ieum-top{background:#15204a!important;color:#fff!important;padding:14px 24px!important;display:flex!important;align-items:center!important;gap:16px!important;flex-wrap:wrap!important}
.ieum-brand{color:#fff!important;text-decoration:none!important;font-size:20px!important;font-weight:1000!important;letter-spacing:0!important;padding:6px 8px!important;border-radius:10px!important}
.ieum-brand:hover,.ieum-brand:focus{background:rgba(255,255,255,.12)!important}
.ieum-nav{display:flex!important;gap:6px!important;flex-wrap:wrap!important;align-items:center!important}
.ieum-nav a{color:#d8e2ff!important;text-decoration:none!important;padding:8px 10px!important;border-radius:6px!important;letter-spacing:0!important}
.ieum-nav a.active,.ieum-nav a:hover{background:#253469!important;color:#fff!important}
.ieum-nav-label{display:inline-flex!important;align-items:center!important;gap:7px!important;min-width:0!important}
.ieum-nav-icon{width:16px!important;height:16px!important;flex:0 0 16px!important;color:currentColor!important}
.ieum-nav-text{display:inline-block!important;min-width:0!important}
.ieum-user{margin-left:auto!important;color:#cbd5e1!important;font-size:13px!important}
.nav-group{position:relative;display:inline-flex}
.nav-group-title{display:inline-flex;color:#d8e2ff!important;text-decoration:none;padding:8px 10px;border-radius:6px}
.nav-group-title:after{content:"";display:inline-block;width:0;height:0;border-left:4px solid transparent;border-right:4px solid transparent;border-top:5px solid currentColor;margin:8px 0 0 7px;opacity:.8}
.nav-group-title.active,.nav-group:hover .nav-group-title,.nav-group-title:focus{background:#253469;color:#fff!important}
.nav-group.settings .nav-group-title{background:rgba(255,255,255,.12)!important;box-shadow:inset 0 0 0 1px rgba(255,255,255,.26);font-weight:1000;color:#fff!important}
.nav-group.settings:hover .nav-group-title,.nav-group.settings:focus-within .nav-group-title,.nav-group.settings .nav-group-title.active{background:#ffd84d!important;color:#15204a!important;box-shadow:inset 0 0 0 1px rgba(21,32,74,.16)!important}
.nav-sub{display:none;position:absolute;left:0;top:100%;z-index:30;min-width:178px;background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:6px;box-shadow:0 12px 26px rgba(15,23,42,.18)}
.nav-group:hover .nav-sub,.nav-group:focus-within .nav-sub{display:grid;gap:4px}
.nav-sub a{color:#111827!important;white-space:nowrap}
.nav-sub a:hover,.nav-sub a.active{background:#eef2ff!important;color:#15204a!important}
@media(max-width:900px){.ieum-user{margin-left:0!important}.nav-group{display:grid}.nav-sub{position:static;margin-top:4px}.nav-group:hover .nav-sub,.nav-group:focus-within .nav-sub{display:grid}}
</style>';
    $html .= '<header class="top ieum-top">';
    $html .= '<a class="ieum-brand" href="' . $brand_url . '">' . get_text($brand) . '</a>';
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
            $group_url = isset($item['url']) && $item['url'] !== '' ? $item['url'] : '';
            if ($group_url === '') {
                foreach ($item['children'] as $first_child) {
                    $group_url = $first_child['url'];
                    break;
                }
            }
            $html .= '<span class="nav-group' . ($key === 'settings' ? ' settings' : '') . '">';
            $html .= '<a class="nav-group-title' . ($child_active ? ' active' : '') . '" href="' . $group_url . '" aria-haspopup="true"><span class="ieum-nav-label">' . ieum_admin_nav_label_html($item) . '</span></a>';
            $html .= '<span class="nav-sub">';
            foreach ($item['children'] as $child_key => $child) {
                $class = $active === $child_key ? ' class="active"' : '';
                $html .= '<a' . $class . ' href="' . $child['url'] . '"><span class="ieum-nav-label">' . ieum_admin_nav_label_html($child) . '</span></a>';
            }
            $html .= '</span></span>';
            continue;
        }

        $class = $active === $key ? ' class="active"' : '';
        $target = isset($item['target']) ? ' target="' . get_text($item['target']) . '" rel="noopener"' : '';
        $html .= '<a' . $class . ' href="' . $item['url'] . '"' . $target . '><span class="ieum-nav-label">' . ieum_admin_nav_label_html($item) . '</span></a>';
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

    $html = '<style>
.ieum-subnav-wrap{background:#fff;border-bottom:1px solid #d9dee7}
.ieum-subnav{max-width:1900px;margin:0 auto;padding:10px 20px;display:flex;gap:8px;align-items:center;overflow-x:auto}
.ieum-subnav-title{flex:0 0 auto;color:#475467;font-size:13px;font-weight:900;margin-right:4px}
.ieum-subnav a{flex:0 0 auto;display:inline-flex;align-items:center;min-height:34px;padding:7px 12px;border:1px solid #d9dee7;border-radius:999px;background:#f8fafc;color:#344054;text-decoration:none;font-size:14px;font-weight:800;white-space:nowrap}
.ieum-subnav a:hover{background:#eef2ff;color:#15204a}
.ieum-subnav a.active{background:#1769c2;border-color:#1769c2;color:#fff}
@media(max-width:640px){.ieum-subnav{padding:8px 12px}.ieum-subnav-title{display:none}.ieum-subnav a{font-size:13px;padding:7px 10px}}
</style>';
    $html .= '<div class="ieum-subnav-wrap"><nav class="ieum-subnav" aria-label="' . get_text($current_group['label']) . ' 하위 메뉴">';
    $html .= '<span class="ieum-subnav-title">' . get_text($current_group['label']) . '</span>';
    foreach ($current_group['children'] as $child_key => $child) {
        $class = $active === $child_key ? ' class="active"' : '';
        $html .= '<a' . $class . ' href="' . $child['url'] . '">' . get_text($child['label']) . '</a>';
    }
    $html .= '</nav></div>';

    return $html;
}
