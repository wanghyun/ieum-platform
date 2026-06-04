<?php
require_once './_common.php';

$g5['title'] = '아이이음 기사님 탑승 확인';
$message = '';
$error = '';

function ieum_driver_login_ensure_columns()
{
    $exists = sql_fetch("show columns from " . IEUM_VEHICLE_ROUTE_TABLE . " like 'driver_pin'", false);
    if (empty($exists['Field'])) {
        sql_query("alter table " . IEUM_VEHICLE_ROUTE_TABLE . " add driver_pin varchar(20) not null default '' after driver_phone", false);
    }
}

ieum_driver_login_ensure_columns();

$academy_code = isset($_REQUEST['academy_code']) ? preg_replace('/[^0-9a-zA-Z_-]/', '', trim($_REQUEST['academy_code'])) : '';
$academy = array();
if ($academy_code !== '') {
    $academy = sql_fetch("
        select academy_id, academy_code, academy_name, service_status, is_active
          from " . IEUM_ACADEMY_TABLE . "
         where academy_code = '" . sql_escape_string($academy_code) . "'
         limit 1
    ", false);
    if (empty($academy['academy_id'])) {
        $error = '도장코드를 확인해 주세요.';
    }
}

if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    unset($_SESSION['ieum_vehicle_driver']);
    $message = '기사님 접속을 종료했습니다.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다. 새로고침 후 다시 시도해 주세요.';
    } else {
        $academy_code = isset($_POST['academy_code']) ? preg_replace('/[^0-9a-zA-Z_-]/', '', trim($_POST['academy_code'])) : '';
        $route_id = isset($_POST['route_id']) ? (int) $_POST['route_id'] : 0;
        $driver_pin = isset($_POST['driver_pin']) ? preg_replace('/[^0-9]/', '', trim($_POST['driver_pin'])) : '';
        $academy = sql_fetch("
            select academy_id, academy_code, academy_name, service_status, is_active
              from " . IEUM_ACADEMY_TABLE . "
             where academy_code = '" . sql_escape_string($academy_code) . "'
             limit 1
        ", false);
        if (empty($academy['academy_id']) || empty($academy['is_active']) || $academy['service_status'] !== 'active') {
            $error = '사용 가능한 도장이 아닙니다. 도장코드를 확인해 주세요.';
        } elseif ($route_id <= 0 || $driver_pin === '') {
            $error = '노선과 기사님 PIN을 입력해 주세요.';
        } else {
            $route = sql_fetch("
                select route_id, route_name, vehicle_label, driver_name, driver_phone, driver_pin
                  from " . IEUM_VEHICLE_ROUTE_TABLE . "
                 where academy_id = '" . (int) $academy['academy_id'] . "'
                   and route_id = '{$route_id}'
                   and is_active = 1
                 limit 1
            ", false);
            if (empty($route['route_id']) || $route['driver_pin'] === '' || $driver_pin !== preg_replace('/[^0-9]/', '', (string) $route['driver_pin'])) {
                $error = '기사님 PIN이 맞지 않습니다.';
            } else {
                $_SESSION['ieum_vehicle_driver'] = array(
                    'academy_id' => (int) $academy['academy_id'],
                    'academy_code' => $academy['academy_code'],
                    'academy_name' => $academy['academy_name'],
                    'route_id' => (int) $route['route_id'],
                    'route_name' => $route['route_name'],
                    'vehicle_label' => $route['vehicle_label'],
                    'driver_name' => $route['driver_name'],
                    'driver_phone' => $route['driver_phone'],
                    'login_at' => G5_TIME_YMDHIS,
                    'expires_at' => date('Y-m-d H:i:s', strtotime(G5_TIME_YMDHIS) + 60 * 60 * 12),
                );
                goto_url(IEUM_URL . '/admin/vehicle_boarding.php?journal_date=' . G5_TIME_YMD . '&route_id=' . (int) $route['route_id'] . '&vehicle_label=' . urlencode($route['vehicle_label']));
            }
        }
    }
}

$routes = array();
if (!empty($academy['academy_id'])) {
    $result = sql_query("
        select route_id, route_name, vehicle_label, driver_name, driver_phone
          from " . IEUM_VEHICLE_ROUTE_TABLE . "
         where academy_id = '" . (int) $academy['academy_id'] . "'
           and is_active = 1
           and driver_pin <> ''
      order by sort_order asc, route_name asc
    ", false);
    while ($row = sql_fetch_array($result)) {
        $routes[] = $row;
    }
}

$csrf_token = ieum_new_csrf_token();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:#eef2f7;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;display:flex;align-items:center;justify-content:center;padding:22px}.card{width:min(520px,100%);background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:24px;box-shadow:0 18px 40px rgba(15,23,42,.12)}h1{margin:0 0 8px;font-size:28px}.meta{color:#667085;line-height:1.5;margin-bottom:18px}.field{display:grid;gap:7px;margin-bottom:12px}.field label{font-weight:900;color:#344054}input,select{width:100%;border:1px solid #cfd6df;border-radius:9px;padding:12px;font-size:16px}.btn{width:100%;min-height:46px;border:1px solid #1769c2;border-radius:9px;background:#1769c2;color:#fff;font-weight:900;font-size:16px;cursor:pointer}.notice{padding:11px 12px;border-radius:9px;margin-bottom:12px;font-weight:800;line-height:1.45}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.hint{margin-top:14px;color:#667085;font-size:13px;line-height:1.5}.route-empty{border:1px solid #f4c27a;background:#fffaf0;color:#915c00;border-radius:9px;padding:12px;line-height:1.5;font-weight:800}
</style>
</head>
<body>
<main class="card">
    <h1>기사님 탑승 확인</h1>
    <div class="meta">도장코드와 기사님 PIN으로 오늘 차량 탑승 확인 화면만 엽니다.</div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="field">
            <label for="academy_code">도장코드</label>
            <input type="text" name="academy_code" id="academy_code" value="<?php echo get_text($academy_code); ?>" placeholder="예: IEUMTKD001" required>
        </div>
        <?php if (!empty($academy['academy_id'])) { ?>
        <div class="field">
            <label for="route_id"><?php echo get_text($academy['academy_name']); ?> 차량/노선</label>
            <?php if (count($routes) > 0) { ?>
            <select name="route_id" id="route_id" required>
                <option value="0">노선 선택</option>
                <?php foreach ($routes as $route) { ?>
                <option value="<?php echo (int) $route['route_id']; ?>"><?php echo get_text(trim(($route['vehicle_label'] ? $route['vehicle_label'] . ' · ' : '') . $route['route_name'] . ($route['driver_name'] ? ' · ' . $route['driver_name'] : ''))); ?></option>
                <?php } ?>
            </select>
            <?php } else { ?>
            <div class="route-empty">기사님 PIN이 설정된 차량 노선이 없습니다. 관리자 차량 관리에서 노선별 PIN을 먼저 설정해 주세요.</div>
            <?php } ?>
        </div>
        <div class="field">
            <label for="driver_pin">기사님 PIN</label>
            <input type="password" name="driver_pin" id="driver_pin" inputmode="numeric" maxlength="8" placeholder="숫자 PIN" required>
        </div>
        <button type="submit" class="btn" <?php echo count($routes) > 0 ? '' : 'disabled'; ?>>탑승 확인 시작</button>
        <?php } else { ?>
        <button type="submit" class="btn">도장 확인</button>
        <?php } ?>
    </form>
    <div class="hint">이 화면은 기사님 전용입니다. 학생관리, 수련비, 문자 설정 같은 관리자 메뉴는 열리지 않습니다.</div>
</main>
</body>
</html>
