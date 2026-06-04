<?php
$sub_menu = '950181';
require_once './_common.php';
require_once IEUM_PATH . '/lib/maps.php';

$g5['title'] = '아이이음 차량 운행 관제';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$map_settings = ieum_map_get_system_settings();
$message = '';
$error = '';

$journal_date = isset($_REQUEST['journal_date']) ? preg_replace('/[^0-9-]/', '', trim($_REQUEST['journal_date'])) : G5_TIME_YMD;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $journal_date)) {
    $journal_date = G5_TIME_YMD;
}

function ieum_monitor_ensure_tables()
{
    $engine = defined('G5_DB_ENGINE') && G5_DB_ENGINE ? G5_DB_ENGINE : 'InnoDB';
    $charset = defined('G5_DB_CHARSET') && G5_DB_CHARSET ? G5_DB_CHARSET : 'utf8';

    sql_query("
        create table if not exists " . IEUM_VEHICLE_RUN_TABLE . " (
            run_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            journal_date date not null,
            ride_type varchar(20) not null,
            route_id int unsigned not null default 0,
            vehicle_label varchar(50) not null default '',
            driver_member_id varchar(50) not null default '',
            status varchar(20) not null default 'active',
            started_at datetime not null,
            ended_at datetime null,
            last_lat decimal(10,7) null,
            last_lng decimal(10,7) null,
            last_location_at datetime null,
            created_at datetime not null,
            updated_at datetime null,
            primary key (run_id),
            key idx_active_run (academy_id, journal_date, ride_type, route_id, vehicle_label, status),
            key idx_driver_day (academy_id, journal_date, driver_member_id)
        ) engine={$engine} default charset={$charset}
    ", false);
}

function ieum_monitor_ride_label($ride_type)
{
    return $ride_type === 'dropoff' ? '하원' : '등원';
}

function ieum_monitor_status_label($status)
{
    if ($status === 'ended') {
        return '운행 종료';
    }
    return '운행 중';
}

function ieum_monitor_boarding_label($status)
{
    $labels = array(
        'boarded' => '탑승',
        'missed' => '미탑승',
        'called' => '통화',
        'self' => '개별',
        'unchecked' => '미확인',
    );

    return isset($labels[$status]) ? $labels[$status] : $status;
}

ieum_monitor_ensure_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    $log_id = isset($_POST['log_id']) ? (int) $_POST['log_id'] : 0;
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다. 다시 시도해 주세요.';
    } elseif ($action === 'resolve_issue' && $log_id > 0) {
        $resolved_by = sql_escape_string(isset($member['mb_id']) ? $member['mb_id'] : '');
        sql_query("
            update " . IEUM_VEHICLE_BOARDING_TABLE . "
               set resolved_by = '{$resolved_by}',
                   resolved_at = '" . G5_TIME_YMDHIS . "',
                   updated_at = '" . G5_TIME_YMDHIS . "'
             where academy_id = '{$academy_id}'
               and journal_date = '" . sql_escape_string($journal_date) . "'
               and log_id = '{$log_id}'
        ");
        $message = '차량 특이사항을 확인완료 처리했습니다.';
    } elseif ($action === 'resolve_issue') {
        $error = '확인완료 처리할 차량 기록이 올바르지 않습니다.';
    }
}

$date_sql = sql_escape_string($journal_date);
$now_sql = sql_escape_string(G5_TIME_YMDHIS);
$weekday_keys = array('sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat');
$weekday_sql = sql_escape_string($weekday_keys[(int) date('w', strtotime($journal_date))]);

$summary = sql_fetch("
    select count(*) as total_count,
           sum(case when status = 'active' then 1 else 0 end) as active_count,
           sum(case when status = 'active' and last_location_at is null then 1 else 0 end) as waiting_count,
           sum(case when status = 'active' and last_location_at is not null and timestampdiff(minute, last_location_at, '{$now_sql}') >= 10 then 1 else 0 end) as stale_count
      from " . IEUM_VEHICLE_RUN_TABLE . "
     where academy_id = '{$academy_id}'
       and journal_date = '{$date_sql}'
", false);

$issue_summary = sql_fetch("
    select sum(case when status = 'missed' and resolved_at is null then 1 else 0 end) as missed_open,
           sum(case when status = 'called' and resolved_at is null then 1 else 0 end) as called_open,
           sum(case when note <> '' and resolved_at is null then 1 else 0 end) as memo_open
      from " . IEUM_VEHICLE_BOARDING_TABLE . "
     where academy_id = '{$academy_id}'
       and journal_date = '{$date_sql}'
", false);

$runs = sql_query("
    select vr.*, r.route_name, r.driver_name, r.driver_phone,
           (select count(*)
              from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
              join " . IEUM_STUDENT_TABLE . " s on s.student_id = sv.student_id and s.academy_id = sv.academy_id and s.is_active = 1
             where sv.academy_id = vr.academy_id
               and sv.ride_type = vr.ride_type
               and sv.route_id = vr.route_id
               and sv.is_active = 1
               and (sv.ride_days = '' or find_in_set('{$weekday_sql}', sv.ride_days))) as expected_count,
           (select count(*)
              from " . IEUM_VEHICLE_BOARDING_TABLE . " bl
             where bl.academy_id = vr.academy_id
               and bl.journal_date = vr.journal_date
               and bl.ride_type = vr.ride_type
               and bl.route_id = vr.route_id
               and bl.status = 'boarded') as boarded_count,
           (select count(*)
              from " . IEUM_VEHICLE_BOARDING_TABLE . " bl
             where bl.academy_id = vr.academy_id
               and bl.journal_date = vr.journal_date
               and bl.ride_type = vr.ride_type
               and bl.route_id = vr.route_id
               and bl.status = 'missed') as missed_count,
           (select count(*)
              from " . IEUM_VEHICLE_BOARDING_TABLE . " bl
             where bl.academy_id = vr.academy_id
               and bl.journal_date = vr.journal_date
               and bl.ride_type = vr.ride_type
               and bl.route_id = vr.route_id
               and bl.status = 'called') as called_count,
           (select count(*)
              from " . IEUM_VEHICLE_BOARDING_TABLE . " bl
             where bl.academy_id = vr.academy_id
               and bl.journal_date = vr.journal_date
               and bl.ride_type = vr.ride_type
               and bl.route_id = vr.route_id
               and bl.status = 'self') as self_count,
           (select count(*)
              from " . IEUM_VEHICLE_BOARDING_TABLE . " bl
             where bl.academy_id = vr.academy_id
               and bl.journal_date = vr.journal_date
               and bl.ride_type = vr.ride_type
               and bl.route_id = vr.route_id
               and (bl.note <> '' or bl.status in ('missed', 'called'))
               and bl.resolved_at is null) as issue_count
      from " . IEUM_VEHICLE_RUN_TABLE . " vr
 left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = vr.route_id and r.academy_id = vr.academy_id
     where vr.academy_id = '{$academy_id}'
       and vr.journal_date = '{$date_sql}'
  order by field(vr.status, 'active', 'ended'), vr.started_at desc, vr.run_id desc
     limit 30
", false);

$issues = sql_query("
    select bl.log_id, bl.status, bl.note, bl.checked_at, bl.ride_type,
           s.student_name, s.student_code, r.vehicle_label, r.route_name, st.stop_name, st.stop_time
      from " . IEUM_VEHICLE_BOARDING_TABLE . " bl
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = bl.student_id and s.academy_id = bl.academy_id
 left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = bl.route_id and r.academy_id = bl.academy_id
 left join " . IEUM_VEHICLE_STOP_TABLE . " st on st.stop_id = bl.stop_id and st.academy_id = bl.academy_id
     where bl.academy_id = '{$academy_id}'
       and bl.journal_date = '{$date_sql}'
       and (bl.note <> '' or bl.status in ('missed', 'called'))
       and bl.resolved_at is null
  order by field(bl.status, 'missed', 'called', 'self', 'boarded'), bl.checked_at desc, bl.log_id desc
     limit 30
", false);

$run_rows = array();
$map_points = array();
while ($run = sql_fetch_array($runs)) {
    $run_rows[] = $run;
    if ($run['last_lat'] !== null && $run['last_lng'] !== null && $run['last_lat'] !== '' && $run['last_lng'] !== '') {
        $map_points[] = array(
            'title' => ieum_monitor_ride_label($run['ride_type']) . ' · ' . ($run['vehicle_label'] ?: '차량 미지정'),
            'route' => $run['route_name'] ?: '노선 미지정',
            'lat' => (float) $run['last_lat'],
            'lng' => (float) $run['last_lng'],
            'last' => $run['last_location_at'] ? substr($run['last_location_at'], 11, 5) : '',
            'status' => $run['status'],
        );
    }
}

$can_use_dynamic_map = !empty($map_settings['use_dynamic_map']) && ieum_map_has_api_key($map_settings);
$auto_refresh = $journal_date === G5_TIME_YMD && (int) $summary['active_count'] > 0;
$csrf_token = ieum_new_csrf_token();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<?php if ($can_use_dynamic_map && count($map_points) > 0) { ?>
<script src="https://oapi.map.naver.com/openapi/v3/maps.js?ncpKeyId=<?php echo get_text($map_settings['naver_client_id']); ?>"></script>
<?php } ?>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#101828;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}h1{margin:0 0 6px;font-size:32px;letter-spacing:0}h2{margin:0 0 14px;font-size:21px}.meta{color:#667085}.notice{padding:12px 14px;border-radius:8px;margin-bottom:14px;font-weight:900}.notice.ok{background:#eef9f1;color:#176b2c;border:1px solid #9bd3ad}.notice.err{background:#fdecec;color:#a4262c;border:1px solid #efb2b2}.hero{display:flex;justify-content:space-between;gap:14px;align-items:flex-end;flex-wrap:wrap;margin-bottom:18px}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.filters input{height:42px;border:1px solid #cfd8e3;border-radius:8px;padding:0 12px;font-size:15px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border:1px solid #cfd8e3;border-radius:8px;background:#fff;color:#101828;text-decoration:none;padding:9px 14px;font-weight:900;cursor:pointer}.primary{background:#1f66c1;border-color:#1f66c1;color:#fff}.live-chip{display:inline-flex;align-items:center;gap:7px;border:1px solid #d9e2f1;border-radius:999px;background:#fff;color:#475467;padding:8px 11px;font-size:13px;font-weight:900}.live-chip.live{background:#e8f7ee;border-color:#bfe7ca;color:#176b2c}.live-dot{width:8px;height:8px;border-radius:999px;background:#98a2b3}.live .live-dot{background:#16a34a}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}.pill{border:1px solid #d9e2f1;border-radius:12px;background:#fff;padding:16px;box-shadow:0 8px 18px rgba(16,24,40,.05)}a.pill{display:block;color:#101828;text-decoration:none}a.pill:hover{border-color:#1f66c1;background:#f8fbff}.pill strong{display:block;font-size:30px;line-height:1.1}.pill span{display:block;color:#667085;font-size:13px;margin-top:6px}.pill.warn{border-color:#f4c27a;background:#fffaf0}.pill.danger{border-color:#f2b3b3;background:#fff5f5}.grid{display:grid;grid-template-columns:1.12fr .88fr;gap:18px;align-items:start}.panel{background:#fff;border:1px solid #d9e2f1;border-radius:12px;padding:18px;box-shadow:0 8px 20px rgba(16,24,40,.06);margin-bottom:18px}.map{height:520px;border:1px solid #d9e2f1;border-radius:12px;background:#eef2f7;overflow:hidden}.map-empty{height:520px;border:1px dashed #cfd8e3;border-radius:12px;background:#f8fafc;color:#667085;display:flex;align-items:center;justify-content:center;text-align:center;font-weight:900;line-height:1.7;padding:20px}.run-list,.issue-list{display:grid;gap:10px}.run-card,.issue-card{border:1px solid #d9e2f1;border-radius:12px;background:#fff;padding:14px;text-decoration:none;color:#101828}.run-card{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:start}.run-card.warn{border-color:#f4c27a;background:#fffaf0}.run-card.danger{border-color:#efb2b2;background:#fff5f5}.run-card.ended{background:#f8fafc;color:#667085}.run-card strong,.issue-card strong{display:block;font-size:17px}.run-card span,.issue-card span{display:block;color:#667085;font-size:13px;margin-top:5px;line-height:1.45}.run-counts{display:grid!important;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px;margin-top:12px!important}.run-count{border-radius:8px;background:#f4f7fb;padding:8px;text-align:center}.run-count b{display:block;font-size:16px}.run-count small{display:block;color:#667085;font-size:11px;font-weight:900}.run-count.good{background:#e8f7ee;color:#176b2c}.run-count.warn{background:#fff4df;color:#915c00}.run-count.danger{background:#fdecec;color:#a4262c}.badge{display:inline-flex;border-radius:999px;background:#eef5ff;color:#1769c2;padding:7px 11px;font-weight:900;font-size:12px;white-space:nowrap}.badge.warn{background:#fff4df;color:#915c00}.badge.danger{background:#fdecec;color:#a4262c}.badge.ended{background:#f2f4f7;color:#667085}.issue-card{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}.issue-card.missed{border-color:#efb2b2;background:#fff5f5}.issue-card.called{border-color:#f4c27a;background:#fffaf0}.issue-actions{display:flex;gap:7px;align-items:center}.mini-btn{height:34px;border:1px solid #cfd8e3;border-radius:8px;background:#fff;font-weight:900;cursor:pointer}.empty{color:#667085;font-size:14px;line-height:1.7}.issue-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px}.issue-stat{border-radius:10px;background:#f8fafc;padding:12px}.issue-stat strong{display:block;font-size:22px}.issue-stat span{font-size:12px;color:#667085;font-weight:900}@media(max-width:980px){.grid{grid-template-columns:1fr}.monitor-side{order:-1}.summary{grid-template-columns:repeat(2,1fr)}.map,.map-empty{height:420px}}@media(max-width:620px){.wrap{padding:0 14px}.summary{grid-template-columns:1fr}.run-card,.issue-card{grid-template-columns:1fr}.run-counts{grid-template-columns:repeat(2,minmax(0,1fr))}.filters .btn,.filters input{width:100%}}
</style>
<style>
body.vehicle-monitor-page-tune .wrap{max-width:none!important;margin:0 86px 0 248px!important;padding:84px 28px 42px!important}
body.vehicle-monitor-page-tune .top,
body.vehicle-monitor-page-tune .ieum-subnav-wrap{display:none!important}
body.vehicle-monitor-page-tune .panel,
body.vehicle-monitor-page-tune .pill,
body.vehicle-monitor-page-tune .run-card,
body.vehicle-monitor-page-tune .issue-card{border-radius:16px;border-color:#dbe3ef;box-shadow:0 12px 28px rgba(15,23,42,.06)}
body.vehicle-monitor-page-tune .map,
body.vehicle-monitor-page-tune .map-empty{border-radius:16px}
@media(max-width:1100px){body.vehicle-monitor-page-tune .wrap{margin:0 74px 0 0!important;padding:84px 18px 32px!important}}
body.ieum-side-layout.ieum-dashboard-page.vehicle-monitor-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    --ieum-shell-top:#fff;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.vehicle-monitor-page-tune .ieum-side{
    width:260px!important;
    background:#fff!important;
    border-right:1px solid #e5e7eb!important;
    box-shadow:none!important;
}
.vehicle-monitor-page-tune .side-brand{height:144px!important;padding:0 28px!important;align-items:center!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important}
.vehicle-monitor-page-tune .side-brand-mark,
.vehicle-monitor-page-tune .side-profile,
.vehicle-monitor-page-tune .side-search,
.vehicle-monitor-page-tune .ieum-right-rail{display:none!important}
.vehicle-monitor-page-tune .side-nav{padding:0 14px 22px!important}
.vehicle-monitor-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}
.vehicle-monitor-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}
.vehicle-monitor-page-tune .ieum-shell-top{left:260px!important;right:0!important;height:64px!important;background:#fff!important;border-bottom:1px solid #eef2f7!important;color:#0f172a!important;box-shadow:none!important}
.vehicle-monitor-page-tune .ieum-shell-link,
.vehicle-monitor-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}
.vehicle-monitor-page-tune .ieum-shell-link::before{display:none!important}
.vehicle-monitor-page-tune .ieum-shell-meta{color:#0f172a!important}
.vehicle-monitor-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.vehicle-monitor-page-tune .dashboard-shell-divider,
.vehicle-monitor-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.vehicle-monitor-page-tune .wrap{max-width:none!important;width:auto!important;margin:0 0 0 260px!important;padding:96px 40px 42px!important}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.vehicle-monitor-page-tune{--ieum-side-width:0px}
    .vehicle-monitor-page-tune .ieum-shell-top{left:0!important}
    body.ieum-side-layout.ieum-dashboard-page.vehicle-monitor-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page vehicle-monitor-page-tune">
<?php echo ieum_admin_header('vehicle_monitor', 'side'); ?>
<main class="wrap">
    <?php if ($message) { ?><div class="notice ok"><?php echo get_text($message); ?></div><?php } ?>
    <?php if ($error) { ?><div class="notice err"><?php echo get_text($error); ?></div><?php } ?>
    <section class="hero">
        <div>
            <h1>차량 운행 관제</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($journal_date); ?> · 기사님 앱의 위치와 탑승 기록을 확인합니다.</div>
        </div>
        <form method="get" class="filters">
            <input type="date" name="journal_date" value="<?php echo get_text($journal_date); ?>">
            <button type="submit" class="btn primary">조회</button>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/vehicle_boarding.php?journal_date=<?php echo get_text($journal_date); ?>">탑승 확인</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/vehicle_journal.php?journal_date=<?php echo get_text($journal_date); ?>">차량 일지</a>
            <span class="live-chip <?php echo $auto_refresh ? 'live' : ''; ?>"><span class="live-dot"></span><?php echo $auto_refresh ? '20초 자동 갱신' : '수동 조회'; ?></span>
        </form>
    </section>

    <section class="summary">
        <a class="pill" href="#runningVehicles"><strong><?php echo number_format((int) $summary['total_count']); ?>건</strong><span>오늘 운행 기록</span></a>
        <a class="pill <?php echo (int) $summary['active_count'] ? 'warn' : ''; ?>" href="#runningVehicles"><strong><?php echo number_format((int) $summary['active_count']); ?>건</strong><span>운행 중</span></a>
        <a class="pill <?php echo (int) $summary['waiting_count'] ? 'warn' : ''; ?>" href="#runningVehicles"><strong><?php echo number_format((int) $summary['waiting_count']); ?>건</strong><span>위치 대기</span></a>
        <a class="pill <?php echo (int) $summary['stale_count'] ? 'danger' : ''; ?>" href="#runningVehicles"><strong><?php echo number_format((int) $summary['stale_count']); ?>건</strong><span>10분 이상 위치 지연</span></a>
    </section>

    <section class="grid">
        <article class="panel">
            <h2>실시간 차량 위치</h2>
            <?php if ($can_use_dynamic_map && count($map_points) > 0) { ?>
                <div id="vehicleMap" class="map"></div>
            <?php } else { ?>
                <div class="map-empty">
                    <?php if (!$can_use_dynamic_map) { ?>
                        네이버 지도 API가 아직 준비되지 않았습니다.<br>본사 관리자에서 지도 API 설정을 확인해 주세요.
                    <?php } else { ?>
                        아직 표시할 차량 위치가 없습니다.<br>기사님 앱에서 운행을 시작하고 위치 권한을 허용하면 이곳에 표시됩니다.
                    <?php } ?>
                </div>
            <?php } ?>
        </article>

        <aside class="monitor-side">
            <article class="panel" id="vehicleIssues">
                <h2>확인 필요한 차량 기록</h2>
                <div class="issue-stats">
                    <div class="issue-stat"><strong><?php echo number_format((int) $issue_summary['missed_open']); ?></strong><span>미탑승</span></div>
                    <div class="issue-stat"><strong><?php echo number_format((int) $issue_summary['called_open']); ?></strong><span>통화</span></div>
                    <div class="issue-stat"><strong><?php echo number_format((int) $issue_summary['memo_open']); ?></strong><span>메모</span></div>
                </div>
                <div class="issue-list">
                    <?php $issue_i = 0; while ($issue = sql_fetch_array($issues)) { $issue_i++; ?>
                        <article class="issue-card <?php echo get_text($issue['status']); ?>">
                            <a href="<?php echo IEUM_URL; ?>/admin/vehicle_boarding.php?journal_date=<?php echo get_text($journal_date); ?>&amp;ride_type=<?php echo get_text($issue['ride_type']); ?>" style="text-decoration:none;color:inherit">
                                <strong><?php echo get_text($issue['student_name'] . ' (' . $issue['student_code'] . ')'); ?></strong>
                                <span><?php echo get_text(ieum_monitor_ride_label($issue['ride_type']) . ' · ' . ($issue['vehicle_label'] ?: '차량 미지정') . ' · ' . ($issue['route_name'] ?: '노선 미지정')); ?></span>
                                <span><?php echo get_text(trim(($issue['stop_time'] ?: '') . ' ' . ($issue['stop_name'] ?: ''))); ?><?php echo $issue['checked_at'] ? ' · ' . get_text(substr($issue['checked_at'], 11, 5)) : ''; ?> · <?php echo get_text(ieum_monitor_boarding_label($issue['status'])); ?></span>
                                <?php if ($issue['note'] !== '') { ?><span><?php echo get_text($issue['note']); ?></span><?php } ?>
                            </a>
                            <form method="post" class="issue-actions">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="journal_date" value="<?php echo get_text($journal_date); ?>">
                                <input type="hidden" name="log_id" value="<?php echo (int) $issue['log_id']; ?>">
                                <button class="mini-btn" type="submit" name="action" value="resolve_issue">확인완료</button>
                            </form>
                        </article>
                    <?php } ?>
                    <?php if ($issue_i === 0) { ?><div class="empty">오늘 확인할 차량 메모가 없습니다.</div><?php } ?>
                </div>
            </article>

            <article class="panel" id="runningVehicles">
                <h2>운행 차량</h2>
                <div class="run-list">
                    <?php foreach ($run_rows as $run) {
                        $last_at = $run['last_location_at'] ? strtotime($run['last_location_at']) : 0;
                        $minutes = $last_at ? floor((strtotime(G5_TIME_YMDHIS) - $last_at) / 60) : null;
                        $state_class = $run['status'] === 'ended' ? 'ended' : ($minutes === null ? 'warn' : ($minutes >= 10 ? 'danger' : ''));
                        $badge_class = $run['status'] === 'ended' ? 'ended' : ($minutes === null ? 'warn' : ($minutes >= 10 ? 'danger' : ''));
                        $badge_label = $run['status'] === 'ended' ? '종료' : ($minutes === null ? '위치 대기' : ($minutes >= 10 ? $minutes . '분 지연' : '위치 정상'));
                        $route_name = $run['route_name'] ?: '노선 미지정';
                        $vehicle_label = $run['vehicle_label'] ?: '차량 미지정';
                        $driver = trim(($run['driver_name'] ?: $run['driver_member_id']) . ($run['driver_phone'] ? ' · ' . $run['driver_phone'] : ''));
                    ?>
                        <a class="run-card <?php echo get_text($state_class); ?>" href="<?php echo IEUM_URL; ?>/admin/vehicle_boarding.php?journal_date=<?php echo get_text($journal_date); ?>&amp;ride_type=<?php echo get_text($run['ride_type']); ?>&amp;vehicle_label=<?php echo urlencode($run['vehicle_label']); ?>&amp;route_id=<?php echo (int) $run['route_id']; ?>">
                            <div>
                                <strong><?php echo get_text(ieum_monitor_ride_label($run['ride_type']) . ' · ' . $vehicle_label); ?></strong>
                                <span><?php echo get_text($route_name); ?> · <?php echo get_text(ieum_monitor_status_label($run['status'])); ?> · 시작 <?php echo get_text(substr($run['started_at'], 11, 5)); ?><?php echo $driver !== '' ? ' · ' . get_text($driver) : ''; ?></span>
                                <span><?php echo $run['last_location_at'] ? '마지막 위치 ' . get_text(substr($run['last_location_at'], 11, 5)) : '아직 위치가 전송되지 않았습니다.'; ?></span>
                                <span class="run-counts">
                                    <span class="run-count"><b><?php echo number_format((int) $run['expected_count']); ?></b><small>대상</small></span>
                                    <span class="run-count good"><b><?php echo number_format((int) $run['boarded_count']); ?></b><small>탑승</small></span>
                                    <span class="run-count danger"><b><?php echo number_format((int) $run['missed_count']); ?></b><small>미탑승</small></span>
                                    <span class="run-count warn"><b><?php echo number_format((int) $run['called_count']); ?></b><small>통화</small></span>
                                    <span class="run-count"><b><?php echo number_format((int) $run['self_count']); ?></b><small>개별</small></span>
                                </span>
                                <?php if ((int) $run['issue_count'] > 0) { ?><span>확인 필요 메모 <?php echo number_format((int) $run['issue_count']); ?>건</span><?php } ?>
                            </div>
                            <span class="badge <?php echo get_text($badge_class); ?>"><?php echo get_text($badge_label); ?></span>
                        </a>
                    <?php } ?>
                    <?php if (count($run_rows) === 0) { ?><div class="empty">아직 운행 시작 기록이 없습니다. 기사님 앱에서 운행을 시작하면 표시됩니다.</div><?php } ?>
                </div>
            </article>
        </aside>
    </section>
</main>
<script>
(function(){
    var rootSelector = '.vehicle-monitor-page-tune.ieum-dashboard-page';
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
<?php if ($auto_refresh) { ?>
<script>
window.setTimeout(function () {
    window.location.reload();
}, 20000);
</script>
<?php } ?>
<?php if ($can_use_dynamic_map && count($map_points) > 0) { ?>
<script>
const vehiclePoints = <?php echo json_encode($map_points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const firstPoint = vehiclePoints[0];
const vehicleMap = new naver.maps.Map('vehicleMap', {
    center: new naver.maps.LatLng(firstPoint.lat, firstPoint.lng),
    zoom: 14,
    zoomControl: true,
    zoomControlOptions: { position: naver.maps.Position.TOP_RIGHT }
});
const bounds = new naver.maps.LatLngBounds();
vehiclePoints.forEach(function (point) {
    const position = new naver.maps.LatLng(Number(point.lat), Number(point.lng));
    bounds.extend(position);
    const marker = new naver.maps.Marker({
        position: position,
        map: vehicleMap,
        title: point.title
    });
    const info = new naver.maps.InfoWindow({
        content: '<div style="padding:10px 12px;font-size:13px;line-height:1.45"><strong>' + point.title + '</strong><br>' + point.route + '<br>최근 위치 ' + (point.last || '대기') + '</div>'
    });
    naver.maps.Event.addListener(marker, 'click', function () {
        info.open(vehicleMap, marker);
    });
});
if (vehiclePoints.length > 1) {
    vehicleMap.fitBounds(bounds);
}
</script>
<?php } ?>
</body>
</html>
