<?php
$sub_menu = '950180';
require_once './_common.php';

$g5['title'] = '아이이음 차량 관리';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

function ieum_vehicle_type_options()
{
    return array(
        'both' => '등원+하원',
        'pickup' => '등원 전용',
        'dropoff' => '하원 전용',
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $route_id = isset($_POST['route_id']) ? (int) $_POST['route_id'] : 0;
        $route_type = isset($_POST['route_type']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['route_type'])) : 'both';
        $route_name = isset($_POST['route_name']) ? trim($_POST['route_name']) : '';
        $vehicle_label = isset($_POST['vehicle_label']) ? trim($_POST['vehicle_label']) : '';
        $driver_name = isset($_POST['driver_name']) ? trim($_POST['driver_name']) : '';
        $driver_phone = isset($_POST['driver_phone']) ? preg_replace('/[^0-9+\-]/', '', trim($_POST['driver_phone'])) : '';
        $sort_order = isset($_POST['sort_order']) ? max(0, (int) $_POST['sort_order']) : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (!isset(ieum_vehicle_type_options()[$route_type])) {
            $route_type = 'both';
        }
        if ($route_name === '') {
            $error = '노선명을 입력하세요.';
        } else {
            $route_type_sql = sql_escape_string($route_type);
            $route_name_sql = sql_escape_string($route_name);
            $vehicle_label_sql = sql_escape_string($vehicle_label);
            $driver_name_sql = sql_escape_string($driver_name);
            $driver_phone_sql = sql_escape_string($driver_phone);

            if ($route_id) {
                sql_query("
                    update " . IEUM_VEHICLE_ROUTE_TABLE . "
                       set route_type = '{$route_type_sql}',
                           route_name = '{$route_name_sql}',
                           vehicle_label = '{$vehicle_label_sql}',
                           driver_name = '{$driver_name_sql}',
                           driver_phone = '{$driver_phone_sql}',
                           sort_order = '{$sort_order}',
                           is_active = '{$is_active}',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where route_id = '{$route_id}'
                       and academy_id = '{$academy_id}'
                ");
                $message = '차량 노선을 수정했습니다.';
            } else {
                sql_query("
                    insert into " . IEUM_VEHICLE_ROUTE_TABLE . "
                        set academy_id = '{$academy_id}',
                            route_type = '{$route_type_sql}',
                            route_name = '{$route_name_sql}',
                            vehicle_label = '{$vehicle_label_sql}',
                            driver_name = '{$driver_name_sql}',
                            driver_phone = '{$driver_phone_sql}',
                            sort_order = '{$sort_order}',
                            is_active = '{$is_active}',
                            created_at = '" . G5_TIME_YMDHIS . "'
                ");
                $message = '차량 노선을 등록했습니다.';
            }
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$routes = sql_query("
    select r.*,
           (select count(*)
              from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
             where sv.academy_id = r.academy_id
               and sv.route_id = r.route_id
               and sv.is_active = 1) as student_count
      from " . IEUM_VEHICLE_ROUTE_TABLE . " r
     where r.academy_id = '{$academy_id}'
  order by r.is_active desc, r.sort_order asc, r.route_name asc
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}
.wrap{max-width:1160px;margin:28px auto;padding:0 20px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}
h1{margin:0 0 8px;font-size:26px}.meta{color:#667085;margin-bottom:16px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}
input,select{border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}.grid{display:grid;grid-template-columns:130px 1fr 120px 130px 130px 130px 90px 90px;gap:8px;align-items:center}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}
table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center}th{background:#72829d;color:#fff}.left{text-align:left}.muted{color:#667085}.inactive{background:#fafafa;color:#8a94a6}
@media(max-width:980px){.grid{grid-template-columns:1fr}table{display:block;overflow-x:auto;white-space:nowrap}}
</style>
</head>
<body>
<?php echo ieum_admin_header('vehicles'); ?>
<main class="wrap">
    <h1>차량 관리</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 등원/하원 노선을 등록하면 학생 관리에서 바로 배정할 수 있습니다.</div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="panel">
        <form method="post" class="grid">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <select name="route_type">
                <?php foreach (ieum_vehicle_type_options() as $value => $label) { ?>
                <option value="<?php echo get_text($value); ?>"><?php echo get_text($label); ?></option>
                <?php } ?>
            </select>
            <input type="text" name="route_name" placeholder="노선명 예: A코스, 학교픽업" maxlength="80" required>
            <input type="text" name="vehicle_label" placeholder="차량명" maxlength="50">
            <input type="text" name="driver_name" placeholder="기사/사범" maxlength="50">
            <input type="text" name="driver_phone" placeholder="연락처" maxlength="30">
            <input type="number" name="sort_order" placeholder="순서" min="0">
            <label><input type="checkbox" name="is_active" value="1" checked> 사용</label>
            <button type="submit" class="btn primary">추가</button>
        </form>
    </section>

    <section class="panel">
        <table>
            <thead>
                <tr>
                    <th>구분</th>
                    <th>노선명</th>
                    <th>차량</th>
                    <th>담당</th>
                    <th>연락처</th>
                    <th>배정 학생</th>
                    <th>상태</th>
                    <th>수정</th>
                </tr>
            </thead>
            <tbody>
            <?php $i = 0; while ($row = sql_fetch_array($routes)) { $i++; ?>
                <tr class="<?php echo $row['is_active'] ? '' : 'inactive'; ?>">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="route_id" value="<?php echo (int) $row['route_id']; ?>">
                        <td>
                            <select name="route_type">
                                <?php foreach (ieum_vehicle_type_options() as $value => $label) { ?>
                                <option value="<?php echo get_text($value); ?>" <?php echo get_selected($row['route_type'], $value); ?>><?php echo get_text($label); ?></option>
                                <?php } ?>
                            </select>
                        </td>
                        <td><input type="text" name="route_name" value="<?php echo get_text($row['route_name']); ?>" maxlength="80"></td>
                        <td><input type="text" name="vehicle_label" value="<?php echo get_text($row['vehicle_label']); ?>" maxlength="50"></td>
                        <td><input type="text" name="driver_name" value="<?php echo get_text($row['driver_name']); ?>" maxlength="50"></td>
                        <td><input type="text" name="driver_phone" value="<?php echo get_text($row['driver_phone']); ?>" maxlength="30"></td>
                        <td><?php echo number_format((int) $row['student_count']); ?>명</td>
                        <td><label><input type="checkbox" name="is_active" value="1" <?php echo $row['is_active'] ? 'checked' : ''; ?>> 사용</label></td>
                        <td>
                            <input type="hidden" name="sort_order" value="<?php echo (int) $row['sort_order']; ?>">
                            <button type="submit" class="btn">저장</button>
                        </td>
                    </form>
                </tr>
            <?php } ?>
            <?php if ($i === 0) { ?><tr><td colspan="8">등록된 차량 노선이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
