<?php
$sub_menu = '950125';
require_once './_common.php';
require_once IEUM_PATH . '/lib/tablet_device.php';

$g5['title'] = '아이이음 앱 출석기 관리';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        if ($action === 'create_pairing') {
            $device_name = isset($_POST['device_name']) ? trim($_POST['device_name']) : '';
            ieum_tablet_device_create_pairing($academy_id, $device_name, isset($member['mb_id']) ? $member['mb_id'] : '');
            $message = '새 출석기 연결 코드가 생성되었습니다. 앱에서 30분 안에 QR을 스캔해 주세요.';
        } elseif ($action === 'update_pin') {
            $tablet_pin = isset($_POST['tablet_pin']) ? preg_replace('/[^0-9]/', '', trim($_POST['tablet_pin'])) : '';
            if (!preg_match('/^\d{4,8}$/', $tablet_pin)) {
                $error = '출석기 PIN은 숫자 4~8자리로 입력해 주세요.';
            } else {
                sql_query("
                    update " . IEUM_ACADEMY_TABLE . "
                       set tablet_pin = '" . sql_escape_string($tablet_pin) . "',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where academy_id = '{$academy_id}'
                ");
                $academy['tablet_pin'] = $tablet_pin;
                $message = '출석기 PIN을 저장했습니다.';
            }
        } elseif ($action === 'revoke') {
            $device_id = isset($_POST['device_id']) ? (int) $_POST['device_id'] : 0;
            sql_query("
                update " . IEUM_TABLET_DEVICE_TABLE . "
                   set status = 'revoked',
                       updated_at = '" . G5_TIME_YMDHIS . "'
                 where academy_id = '{$academy_id}'
                   and device_id = '{$device_id}'
            ");
            $message = '출석기 연결을 해제했습니다.';
        } elseif ($action === 'update_device_name') {
            $device_id = isset($_POST['device_id']) ? (int) $_POST['device_id'] : 0;
            $device_name = isset($_POST['device_name']) ? trim($_POST['device_name']) : '';
            if ($device_id <= 0 || $device_name === '') {
                $error = '태블릿 이름을 입력해 주세요.';
            } else {
                sql_query("
                    update " . IEUM_TABLET_DEVICE_TABLE . "
                       set device_name = '" . sql_escape_string($device_name) . "',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where academy_id = '{$academy_id}'
                       and device_id = '{$device_id}'
                       and status in ('pending', 'active')
                ");
                $message = '태블릿 이름을 변경했습니다.';
            }
        } elseif ($action === 'cleanup_expired') {
            sql_query("
                delete from " . IEUM_TABLET_DEVICE_TABLE . "
                 where academy_id = '{$academy_id}'
                   and status = 'pending'
                   and expires_at < '" . G5_TIME_YMDHIS . "'
            ");
            $message = '만료된 연결 코드를 정리했습니다.';
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$devices = sql_query("
    select *
     from " . IEUM_TABLET_DEVICE_TABLE . "
     where academy_id = '{$academy_id}'
       and status in ('pending', 'active')
  order by case
           when status = 'active' then 1
           when status = 'pending' and expires_at >= '" . G5_TIME_YMDHIS . "' then 2
           else 3
       end,
       device_id desc
", false);

$device_counts = array(
    'active' => 0,
    'pending' => 0,
    'expired' => 0,
);
$count = sql_fetch("
    select sum(case when status = 'active' then 1 else 0 end) as active_cnt,
           sum(case when status = 'pending' and expires_at >= '" . G5_TIME_YMDHIS . "' then 1 else 0 end) as pending_cnt,
           sum(case when status = 'pending' and expires_at < '" . G5_TIME_YMDHIS . "' then 1 else 0 end) as expired_cnt
      from " . IEUM_TABLET_DEVICE_TABLE . "
     where academy_id = '{$academy_id}'
       and status in ('pending', 'active')
", false);
$device_counts['active'] = (int) $count['active_cnt'];
$device_counts['pending'] = (int) $count['pending_cnt'];
$device_counts['expired'] = (int) $count['expired_cnt'];

$latest_pending = sql_fetch("
    select *
      from " . IEUM_TABLET_DEVICE_TABLE . "
     where academy_id = '{$academy_id}'
       and status = 'pending'
       and expires_at >= '" . G5_TIME_YMDHIS . "'
  order by device_id desc
     limit 1
", false);

$tablet_pin = isset($academy['tablet_pin']) ? $academy['tablet_pin'] : '110022';
$default_server_url = preg_replace('#/+$#', '', IEUM_URL);
$request_host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
if (preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $request_host)) {
    $default_server_url = 'http://192.168.0.81/ieum';
}

function ieum_tablet_time_text($datetime)
{
    if (!$datetime) {
        return '-';
    }

    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return get_text($datetime);
    }

    $diff = strtotime(G5_TIME_YMDHIS) - $timestamp;
    if ($diff < 60) {
        return '방금 전';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . '분 전';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . '시간 전';
    }

    return date('m-d H:i', $timestamp);
}

function ieum_tablet_pairing_time_text($datetime)
{
    if (!$datetime) {
        return '-';
    }

    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return get_text($datetime);
    }

    return date('Y-m-d H:i', $timestamp);
}

function ieum_tablet_remaining_text($datetime)
{
    if (!$datetime) {
        return '';
    }

    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return '';
    }

    $remaining = $timestamp - strtotime(G5_TIME_YMDHIS);
    if ($remaining <= 0) {
        return '만료됨';
    }
    if ($remaining < 3600) {
        return floor($remaining / 60) . '분 남음';
    }

    return date('H:i까지', $timestamp);
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<script src="<?php echo IEUM_URL; ?>/assets/js/qrcode.min.js"></script>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}
.wrap{max-width:1900px;margin:28px auto;padding:0 20px}body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}.bar{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}h1{margin:0;font-size:30px}h2{margin:0 0 14px;font-size:22px}.muted{color:#667085}.panel{background:#fff;border:1px solid #d9dee7;border-radius:10px;padding:22px;margin-top:18px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.notice{padding:12px 14px;border-radius:8px;margin:0 0 14px}.ok{background:#eaf7ef;color:#0f7a3a}.err{background:#fdecec;color:#a4262c}.btn{border:1px solid #cfd6df;background:#fff;border-radius:8px;padding:10px 14px;font-weight:900;cursor:pointer;text-decoration:none;color:#111827;display:inline-flex;align-items:center;justify-content:center}.btn.primary{background:#2248bf;border-color:#2248bf;color:#fff}.btn.danger{border-color:#fecaca;color:#b42318}input{height:42px;border:1px solid #cfd6df;border-radius:8px;padding:0 12px;width:100%;background:#fff}.connect-grid{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:18px;align-items:stretch}.summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:14px 0}.summary-card{border:1px solid #d9e1ec;border-radius:10px;padding:14px;background:#f8fafc}.summary-card span{display:block;color:#667085;font-size:13px}.summary-card strong{display:block;margin-top:4px;font-size:18px}.steps{display:grid;grid-template-columns:1fr;gap:10px;margin-top:16px}.step{display:grid;grid-template-columns:38px 1fr;gap:10px;align-items:start;border:1px solid #d9e1ec;border-radius:10px;padding:12px;background:#fff}.step-no{width:30px;height:30px;border-radius:50%;background:#2248bf;color:#fff;font-weight:900;display:flex;align-items:center;justify-content:center}.step strong{display:block;margin-bottom:3px}.form-inline{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end;margin-top:14px}label{font-weight:900;display:grid;gap:6px}.qr-panel{border:2px solid #2248bf;border-radius:14px;background:#eef6ff;padding:20px;text-align:center}.qr-panel.empty{border-color:#d8dee9;background:#f8fafc}.code{font-size:48px;font-weight:1000;letter-spacing:8px;color:#2248bf}.expires{margin-top:2px;color:#667085}.qr-image{width:240px;height:240px;margin:16px auto 0;padding:10px;border-radius:12px;background:#fff;box-shadow:0 8px 20px rgba(15,23,42,.08);display:flex;align-items:center;justify-content:center}.qr-image img{display:block}.qr-server{margin-top:12px;text-align:left}.qr-server small{font-weight:400;color:#667085}.hint{font-size:13px;line-height:1.6;color:#667085;margin:10px 0 0}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;background:#fff;min-width:760px}th,td{border:1px solid #d8dee9;padding:10px;text-align:left;vertical-align:middle}th{background:#71829f;color:#fff}.status{display:inline-flex;border-radius:999px;background:#eef2f7;padding:5px 9px;font-weight:900}.status.active{background:#eaf7ef;color:#0f7a3a}.status.pending{background:#fff6db;color:#946200}.status.revoked{background:#f2f4f7;color:#667085}.guide-card{background:#fbfcff;border:1px solid #d9e1ec;border-radius:10px;padding:14px;margin-top:14px}.guide-card ul{margin:8px 0 0;padding-left:18px;line-height:1.8;color:#475467}@media(max-width:920px){.connect-grid{grid-template-columns:1fr}.steps{grid-template-columns:1fr}.summary{grid-template-columns:1fr}.form-inline{grid-template-columns:1fr}.code{font-size:40px}}
</style>
<style>
.qr-warning{display:none;margin-top:8px;padding:9px 10px;border-radius:8px;background:#fff1f0;color:#b42318;font-size:13px;line-height:1.45}
.qr-warning.on{display:block}
.device-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}.chips{display:flex;gap:8px;flex-wrap:wrap}.chip{display:inline-flex;align-items:center;border-radius:999px;background:#eef2f7;color:#344054;padding:7px 10px;font-weight:900;font-size:13px}.chip.good{background:#eaf7ef;color:#0f7a3a}.chip.warn{background:#fff6db;color:#946200}.chip.expired{background:#fff1f0;color:#b42318}.device-name-form{display:flex;gap:6px;align-items:center}.device-name-form input{min-width:180px}.actions{display:flex;gap:6px;flex-wrap:wrap}.btn.small{height:38px;padding:8px 10px;font-size:13px}.btn.ghost{background:#f8fafc}.status.expired{background:#fff1f0;color:#b42318}.device-meta{display:block;margin-top:4px;color:#667085;font-size:12px}.device-code{font-size:20px;font-weight:1000;letter-spacing:2px;color:#1947ba}.device-help{display:block;margin-top:3px;color:#667085;font-size:12px}.empty-state{padding:28px;text-align:center;color:#667085}.guide-card strong{font-size:18px}.setup-banner{display:grid;grid-template-columns:1.2fr .8fr;gap:14px;align-items:stretch;background:#f7f9ff;border:1px solid #dbe5ff;border-radius:12px;padding:16px;margin-top:18px}.setup-banner h2{margin-bottom:8px}.setup-list{display:grid;gap:8px}.setup-item{display:flex;gap:8px;align-items:center;color:#344054}.setup-dot{width:24px;height:24px;border-radius:999px;background:#2248bf;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:1000;flex:0 0 auto}.manual-card{background:#15204a;color:#fff;border-radius:12px;padding:16px}.manual-card strong{display:block;margin-bottom:6px;font-size:18px}.manual-card p{margin:0;color:#d8e2ff;line-height:1.6}.quick-help{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.help-card{border:1px solid #d9e1ec;border-radius:10px;background:#fff;padding:14px}.help-card strong{display:block;margin-bottom:6px}.help-card p{margin:0;color:#667085;font-size:13px;line-height:1.6}.form-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,.45fr);gap:10px;align-items:end}@media(max-width:920px){.setup-banner,.quick-help,.form-row{grid-template-columns:1fr}}
</style>
<style>
/* Dashboard shell alignment: the device screen should feel like the same admin desk. */
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .ieum-side{
    width:260px!important;
    background:#fff!important;
    border-right:1px solid #e2e8f0!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .side-brand{
    display:flex!important;
    height:144px!important;
    min-height:144px!important;
    padding:0 28px!important;
    background:#fff!important;
    color:#0f172a!important;
    font-size:29px!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .side-search{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .side-nav{
    padding:0 14px 24px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .side-main-link,
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .side-menu>summary{
    min-height:42px!important;
    border-radius:6px!important;
    padding:0 12px!important;
    color:#0f172a!important;
    font-size:15px!important;
    font-weight:900!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .side-main-link:hover,
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .side-main-link.active,
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .side-menu[open]>summary,
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .side-menu>summary:hover{
    background:#f1f5f9!important;
    color:#0f172a!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .ieum-nav-label{
    gap:10px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .ieum-nav-icon{
    width:18px!important;
    height:18px!important;
    color:#334155!important;
    opacity:1!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .side-sub{
    margin:2px 0 8px!important;
    padding:0 0 0 28px!important;
    background:transparent!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .side-sub a{
    min-height:34px!important;
    border-radius:6px!important;
    color:#475569!important;
    font-size:14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .side-sub a:hover,
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .side-sub a.active{
    background:#f1f5f9!important;
    color:#0f172a!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .ieum-shell-top{
    left:260px!important;
    right:0!important;
    width:auto!important;
    height:64px!important;
    padding:0 40px!important;
    background:#fff!important;
    border-bottom:1px solid #e2e8f0!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .ieum-shell-link{
    flex:0 0 auto!important;
    color:#0f172a!important;
    font-weight:900!important;
    text-decoration:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .ieum-shell-link:before{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .ieum-shell-link:hover{
    color:#1769c2!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .ieum-shell-meta{
    margin-left:auto!important;
    color:#0f172a!important;
    font-size:13px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .dashboard-shell-meta-inner{
    display:flex!important;
    align-items:center!important;
    justify-content:flex-end!important;
    gap:8px!important;
    white-space:nowrap!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .dashboard-shell-divider,
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .dashboard-shell-help-dot{
    color:#94a3b8!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .dashboard-shell-clock{
    font-weight:1000!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .dashboard-shell-support-link{
    color:#0f172a!important;
    text-decoration:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .dashboard-shell-support-link:hover{
    color:#1769c2!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .dashboard-shell-help-group{
    display:inline-flex!important;
    align-items:center!important;
    gap:4px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .ieum-right-rail{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .wrap{
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
    max-width:none!important;
    width:auto!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .bar{
    align-items:flex-end!important;
    margin-bottom:18px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .bar h1{
    font-size:30px!important;
    font-weight:1000!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .bar .muted{
    margin:8px 0 0!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .actions{
    align-items:center!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .btn{
    min-height:38px!important;
    border-radius:6px!important;
    font-size:14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .setup-banner,
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .panel,
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .guide-card{
    border-color:#dfe5ee!important;
    border-radius:8px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .setup-banner{
    margin-top:0!important;
    background:#fff!important;
    padding:18px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .manual-card{
    background:#f8fafc!important;
    color:#0f172a!important;
    border:1px solid #e2e8f0!important;
    border-radius:8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .manual-card p{
    color:#475569!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .connect-grid{
    grid-template-columns:minmax(0,1fr) minmax(360px,420px)!important;
    gap:16px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .summary-card,
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .step,
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .help-card{
    border-radius:8px!important;
    border-color:#e2e8f0!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .qr-panel{
    border-radius:8px!important;
    border:1px solid #cfe4ff!important;
    background:#f0f7ff!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune table{
    min-width:860px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune th{
    background:#f3f6fb!important;
    color:#334155!important;
    border-color:#e2e8f0!important;
}
body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune td{
    border-color:#e5ebf3!important;
}
@media(max-width:1500px){
    body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .connect-grid{
        grid-template-columns:1fr!important;
    }
}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .wrap{
        margin:0!important;
        padding:86px 14px 34px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.tablet-page-tune .ieum-shell-top{
        left:0!important;
        right:0!important;
        padding:0 10px!important;
    }
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page tablet-page-tune">
<?php echo ieum_admin_header('tablet_devices', 'side'); ?>
<main class="wrap">
    <div class="bar">
        <div>
            <h1>앱 출석기 관리</h1>
            <p class="muted"><?php echo get_text($academy['academy_name']); ?> 전용 출석 앱을 연결하고 관리합니다.</p>
        </div>
        <div class="actions">
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/attendance_today.php">오늘 출석</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/student_groups.php">부별 명단</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/students.php">원생 관리</a>
        </div>
    </div>

    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="setup-banner">
        <div>
            <h2>처음 연결은 이 순서로 하면 됩니다</h2>
            <div class="setup-list">
                <div class="setup-item"><span class="setup-dot">1</span><span>태블릿에 <strong>아이이음출석기</strong> 앱을 설치하고 실행합니다.</span></div>
                <div class="setup-item"><span class="setup-dot">2</span><span>앱 첫 화면에서 <strong>도장 연결하기</strong>를 누릅니다.</span></div>
                <div class="setup-item"><span class="setup-dot">3</span><span>이 화면에서 <strong>연결 코드 생성</strong>을 누른 뒤 QR을 스캔합니다.</span></div>
                <div class="setup-item"><span class="setup-dot">4</span><span>연결 완료 후 앱 화면이 <strong><?php echo get_text($academy['academy_name']); ?></strong> 전용 숫자 입력 화면으로 바뀝니다.</span></div>
            </div>
        </div>
        <div class="manual-card">
            <strong>처음 설치 안내</strong>
            <p>운영 서버에서는 도메인으로 연결하고, 로컬 테스트에서는 태블릿이 접속할 수 있는 PC IP 주소를 QR에 넣어야 합니다. 앱이 이미 연결된 태블릿은 바로 출석 화면이 열립니다.</p>
        </div>
    </section>

    <section class="panel connect-grid">
        <div>
            <h2>새 앱 출석기 연결</h2>
            <p class="muted">플레이스토어에서 설치한 <strong>아이이음출석기</strong> 앱을 이 도장 전용 출석기로 등록합니다.</p>

            <div class="summary">
                <div class="summary-card">
                    <span>도장 코드</span>
                    <strong><?php echo get_text($academy['academy_code']); ?></strong>
                </div>
                <div class="summary-card">
                    <span>앱 관리자 PIN</span>
                    <strong><?php echo get_text($tablet_pin); ?></strong>
                </div>
                <div class="summary-card">
                    <span>연결 방식</span>
                    <strong>QR 스캔</strong>
                </div>
            </div>

            <div class="steps">
                <div class="step">
                    <span class="step-no">1</span>
                    <div><strong>연결 코드 생성</strong><span class="muted">아래에서 태블릿 위치를 적고 연결 코드를 만듭니다.</span></div>
                </div>
                <div class="step">
                    <span class="step-no">2</span>
                    <div><strong>앱 첫 화면에서 도장 연결</strong><span class="muted">새 태블릿은 숫자 화면이 아니라 도장 연결 화면이 먼저 나옵니다. 도장 연결하기를 누릅니다.</span></div>
                </div>
                <div class="step">
                    <span class="step-no">3</span>
                    <div><strong>QR 스캔 후 완료 확인</strong><span class="muted">오른쪽 QR을 읽고 연결 완료 문구가 나오면 바로 출석기로 사용할 수 있습니다.</span></div>
                </div>
            </div>

            <div class="form-row">
                <form method="post" class="form-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
                    <input type="hidden" name="action" value="create_pairing">
                    <label>태블릿 이름
                        <input type="text" name="device_name" placeholder="예: 1층 입구 태블릿">
                    </label>
                    <button type="submit" class="btn primary">연결 코드 생성</button>
                </form>

                <form method="post" class="form-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
                    <input type="hidden" name="action" value="update_pin">
                    <label>관리자 PIN
                        <input type="text" name="tablet_pin" inputmode="numeric" maxlength="8" value="<?php echo get_text($tablet_pin); ?>" placeholder="숫자 4~8자리">
                    </label>
                    <button type="submit" class="btn">PIN 저장</button>
                </form>
            </div>
        </div>

        <div class="qr-panel<?php echo $latest_pending ? '' : ' empty'; ?>">
            <div class="muted">태블릿 앱에서 스캔할 QR</div>
            <?php if ($latest_pending) { ?>
                <div class="code"><?php echo get_text($latest_pending['pairing_code']); ?></div>
                <div class="expires">만료: <?php echo get_text($latest_pending['expires_at']); ?></div>
                <div id="pairingQr" class="qr-image" aria-label="출석기 연결 QR"></div>
                <label class="qr-server">태블릿에서 접속할 서버 주소
                    <input type="text" id="qrServerUrl" value="<?php echo get_text($default_server_url); ?>" placeholder="예: http://192.168.0.81">
                    <small>로컬 테스트는 localhost 대신 PC IP를 사용합니다.</small>
                    <span id="qrServerWarning" class="qr-warning">태블릿에서는 localhost로 PC에 접속할 수 없습니다. PC IP 또는 운영 도메인을 입력해 주세요.</span>
                </label>
                <p class="hint">연결 코드는 30분 동안만 유효합니다. 연결이 끝나면 이 코드는 다시 사용할 수 없습니다.</p>
                <script>
                (function () {
                    var serverInput = document.getElementById('qrServerUrl');
                    var serverWarning = document.getElementById('qrServerWarning');
                    var qr = document.getElementById('pairingQr');
                    var academyCode = <?php echo json_encode($academy['academy_code']); ?>;
                    var tabletPin = <?php echo json_encode($tablet_pin); ?>;
                    var pairingCode = <?php echo json_encode($latest_pending['pairing_code']); ?>;
                    function updateQr() {
                        var server = (serverInput.value || '').replace(/\/+$/, '');
                        var isLocalhost = /^(https?:\/\/)?(localhost|127\.0\.0\.1|\[::1\])(?::\d+)?(\/|$)/i.test(server);
                        serverWarning.className = isLocalhost ? 'qr-warning on' : 'qr-warning';
                        var payload = 'ieum-attendance://pair'
                            + '?server=' + encodeURIComponent(server)
                            + '&academy_code=' + encodeURIComponent(academyCode)
                            + '&tablet_pin=' + encodeURIComponent(tabletPin)
                            + '&pairing_code=' + encodeURIComponent(pairingCode);
                        qr.innerHTML = '';
                        new QRCode(qr, {
                            text: payload,
                            width: 220,
                            height: 220,
                            colorDark: '#15204a',
                            colorLight: '#ffffff',
                            correctLevel: QRCode.CorrectLevel.M
                        });
                    }
                    serverInput.addEventListener('input', updateQr);
                    updateQr();
                })();
                </script>
            <?php } else { ?>
                <div class="code">------</div>
                <p class="hint">왼쪽에서 연결 코드를 생성하면 태블릿 앱에서 스캔할 QR이 표시됩니다.</p>
            <?php } ?>
        </div>
    </section>

    <section class="panel">
        <div class="device-head">
            <div>
                <h2>등록된 앱 출석기</h2>
                <p class="muted" style="margin:4px 0 0">현재 사용하는 태블릿 앱과 연결 대기 코드를 관리합니다.</p>
            </div>
            <div class="chips">
                <span class="chip good">연결됨 <?php echo (int) $device_counts['active']; ?>대</span>
                <span class="chip warn">QR 대기 <?php echo (int) $device_counts['pending']; ?>건</span>
                <span class="chip expired">만료됨 <?php echo (int) $device_counts['expired']; ?>건</span>
            </div>
            <?php if ($device_counts['expired'] > 0) { ?>
                <form method="post" onsubmit="return confirm('만료된 연결 코드를 정리할까요?');">
                    <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
                    <input type="hidden" name="action" value="cleanup_expired">
                    <button type="submit" class="btn small">만료 코드 정리</button>
                </form>
            <?php } ?>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>상태</th>
                    <th>태블릿 이름</th>
                    <th>연결 코드</th>
                    <th>연결일</th>
                    <th>최근 사용</th>
                    <th>관리</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $has_row = false;
                while ($row = sql_fetch_array($devices)) {
                    $has_row = true;
                    $status = $row['status'];
                    $is_expired = $status === 'pending' && $row['expires_at'] && strtotime($row['expires_at']) < strtotime(G5_TIME_YMDHIS);
                    $status_class = $is_expired ? 'expired' : $status;
                ?>
                    <tr>
                        <td><span class="status <?php echo get_text($status_class); ?>"><?php echo get_text(ieum_tablet_device_status_label($status, $row['expires_at'])); ?></span></td>
                        <td>
                            <form method="post" class="device-name-form">
                                <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
                                <input type="hidden" name="action" value="update_device_name">
                                <input type="hidden" name="device_id" value="<?php echo (int) $row['device_id']; ?>">
                                <input type="text" name="device_name" value="<?php echo get_text($row['device_name']); ?>" placeholder="예: 입구 태블릿">
                                <button type="submit" class="btn ghost small">이름 변경</button>
                            </form>
                        </td>
                        <td>
                            <?php if ($status === 'pending') { ?>
                                <span class="device-code"><?php echo get_text($row['pairing_code']); ?></span>
                                <span class="device-help"><?php echo get_text(ieum_tablet_remaining_text($row['expires_at'])); ?></span>
                            <?php } else { ?>
                                <span class="muted">연결 완료</span>
                            <?php } ?>
                        </td>
                        <td>
                            <?php echo $row['paired_at'] ? get_text(ieum_tablet_pairing_time_text($row['paired_at'])) : '-'; ?>
                            <?php if ($row['created_by']) { ?><span class="device-meta">생성: <?php echo get_text($row['created_by']); ?></span><?php } ?>
                        </td>
                        <td>
                            <?php echo $row['last_seen_at'] ? get_text(ieum_tablet_time_text($row['last_seen_at'])) : '-'; ?>
                            <?php if ($row['last_seen_at']) { ?><span class="device-meta"><?php echo get_text($row['last_seen_at']); ?></span><?php } ?>
                        </td>
                        <td>
                            <?php if ($status === 'active' || $status === 'pending') { ?>
                                <form method="post" onsubmit="return confirm('이 출석기의 연결을 해제할까요?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="device_id" value="<?php echo (int) $row['device_id']; ?>">
                                    <button type="submit" class="btn danger small"><?php echo $status === 'pending' ? 'QR 취소' : '연결 해제'; ?></button>
                                </form>
                            <?php } else { ?>
                                -
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (!$has_row) { ?>
                    <tr><td colspan="6" class="empty-state">등록된 출석기가 없습니다. 위에서 연결 코드를 먼저 생성해 주세요.</td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="device-head">
            <div>
                <h2>연결이 안 될 때</h2>
                <p class="muted" style="margin:4px 0 0">현장에서 가장 자주 막히는 부분만 바로 확인할 수 있게 정리했습니다.</p>
            </div>
        </div>
        <div class="quick-help">
            <div class="help-card">
                <strong>QR 스캔 후 연결 오류</strong>
                <p>로컬 테스트라면 서버 주소가 localhost가 아닌 PC IP인지 확인하세요. 태블릿과 PC는 같은 공유기에 있어야 합니다.</p>
            </div>
            <div class="help-card">
                <strong>카메라가 열리지 않음</strong>
                <p>태블릿 앱 권한에서 카메라 권한을 허용하고 앱을 완전히 종료한 뒤 다시 실행하세요.</p>
            </div>
            <div class="help-card">
                <strong>연결 코드를 놓침</strong>
                <p>30분이 지나면 만료됩니다. 만료 코드를 정리하고 새 연결 코드를 생성하면 됩니다.</p>
            </div>
        </div>
    </section>

    <section class="guide-card">
        <strong>운영 메모</strong>
        <ul>
            <li>PIN은 아이들이 알 수 없게 관리하고, 필요하면 도장별로 변경하세요.</li>
            <li>태블릿을 교체하거나 분실하면 기존 기기는 바로 연결 해제하세요.</li>
            <li>오픈 후 운영 서버에서는 서버 주소가 고정 도메인으로 자동 표시됩니다. 로컬 테스트에서만 PC IP를 입력합니다.</li>
        </ul>
    </section>
</main>
<script>
(function(){
    var rootSelector = '.tablet-page-tune.ieum-dashboard-page';
    var brandText = document.querySelector(rootSelector + ' .side-brand span:last-child');
    if (brandText) {
        brandText.textContent = <?php echo json_encode($academy['academy_name']); ?>;
    }

    var homeLink = document.querySelector(rootSelector + ' .ieum-shell-link');
    if (homeLink) {
        homeLink.textContent = '아이이음 교육페이지';
    }

    var meta = document.querySelector(rootSelector + ' .ieum-shell-meta');
    if (meta) {
        var now = new Date();
        var hh = String(now.getHours()).padStart(2, '0');
        var mm = String(now.getMinutes()).padStart(2, '0');
        meta.innerHTML = ''
            + '<span class="dashboard-shell-meta-inner">'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php">개발지원센터</a>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span class="dashboard-shell-help-group">'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#qna">Q&A</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#faq">자주하는 질문</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#contact">문의하기</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#chatbot">AI 챗봇</a>'
            + '</span>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span><?php echo get_text($academy['academy_name']); ?></span>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span class="dashboard-shell-clock">' + hh + ':' + mm + '</span>'
            + '</span>';
    }
})();
</script>
</body>
</html>
