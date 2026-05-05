<?php
$sub_menu = '950140';
require_once './_common.php';
ieum_require_head_admin_page();

$g5['title'] = '아이이음 도장 관리';

$mode = isset($_GET['mode']) ? trim($_GET['mode']) : 'list';
$academy_id = isset($_GET['academy_id']) ? (int) $_GET['academy_id'] : 0;
$message = '';
$error = '';

function ieum_random_gateway_token()
{
    if (function_exists('random_bytes')) {
        $bytes = random_bytes(18);
    } elseif (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = openssl_random_pseudo_bytes(18);
    } else {
        $bytes = uniqid(mt_rand(), true);
    }

    return 'ieum-' . bin2hex($bytes);
}

function ieum_valid_time($value)
{
    return preg_match('/^\d{2}:\d{2}$/', $value)
        && substr($value, 0, 2) >= '00'
        && substr($value, 0, 2) <= '23'
        && substr($value, 3, 2) >= '00'
        && substr($value, 3, 2) <= '59';
}

function ieum_fetch_academy($academy_id)
{
    $academy_id = (int) $academy_id;
    if (!$academy_id) {
        return null;
    }

    $row = sql_fetch("
        select *
          from " . IEUM_ACADEMY_TABLE . "
         where academy_id = '{$academy_id}'
         limit 1
    ", false);

    return isset($row['academy_id']) ? $row : null;
}

function ieum_member_exists($mb_id)
{
    global $g5;

    $mb_id_sql = sql_escape_string(trim($mb_id));
    if ($mb_id_sql === '') {
        return true;
    }

    $row = sql_fetch("
        select mb_id
          from {$g5['member_table']}
         where mb_id = '{$mb_id_sql}'
         limit 1
    ", false);

    return isset($row['mb_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다. 새로고침 후 다시 시도하세요.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        $post_academy_id = isset($_POST['academy_id']) ? (int) $_POST['academy_id'] : 0;

        if ($action === 'save') {
            $mb_id = isset($_POST['mb_id']) ? preg_replace('/[^0-9A-Za-z_@.-]/', '', trim($_POST['mb_id'])) : '';
            $academy_code = isset($_POST['academy_code']) ? preg_replace('/[^0-9A-Za-z_-]/', '', trim($_POST['academy_code'])) : '';
            $academy_name = isset($_POST['academy_name']) ? trim($_POST['academy_name']) : '';
            $gateway_token = isset($_POST['gateway_token']) ? trim($_POST['gateway_token']) : '';
            $service_status = isset($_POST['service_status']) ? preg_replace('/[^a-z]/', '', trim($_POST['service_status'])) : 'pending';
            $sms_start_time = isset($_POST['sms_start_time']) ? trim($_POST['sms_start_time']) : '10:00';
            $sms_end_time = isset($_POST['sms_end_time']) ? trim($_POST['sms_end_time']) : '20:00';
            $sms_poll_seconds = isset($_POST['sms_poll_seconds']) ? (int) $_POST['sms_poll_seconds'] : 30;
            $promotion_interval_months = isset($_POST['promotion_interval_months']) ? (int) $_POST['promotion_interval_months'] : 3;
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($gateway_token === '') {
                $gateway_token = ieum_random_gateway_token();
            }

            if ($sms_poll_seconds < 15) {
                $sms_poll_seconds = 15;
            } elseif ($sms_poll_seconds > 3600) {
                $sms_poll_seconds = 3600;
            }
            if (!in_array($promotion_interval_months, array(1, 2, 3), true)) {
                $promotion_interval_months = 3;
            }

            if (!in_array($service_status, array('pending', 'active', 'suspended'), true)) {
                $service_status = 'pending';
            }

            if ($mb_id !== '' && !ieum_member_exists($mb_id)) {
                $error = '존재하지 않는 회원 ID입니다.';
            } elseif ($academy_code === '') {
                $error = '도장 코드를 입력하세요.';
            } elseif ($academy_name === '') {
                $error = '도장명을 입력하세요.';
            } elseif (!ieum_valid_time($sms_start_time) || !ieum_valid_time($sms_end_time)) {
                $error = '운영 시간은 HH:mm 형식으로 입력하세요.';
            } else {
                $academy_code_sql = sql_escape_string($academy_code);
                $gateway_token_sql = sql_escape_string($gateway_token);

                $duplicate = sql_fetch("
                    select academy_id
                      from " . IEUM_ACADEMY_TABLE . "
                     where (academy_code = '{$academy_code_sql}' or gateway_token = '{$gateway_token_sql}')
                       " . ($post_academy_id ? "and academy_id <> '{$post_academy_id}'" : "") . "
                     limit 1
                ", false);

                if (isset($duplicate['academy_id'])) {
                    $error = '이미 사용 중인 도장 코드 또는 토큰입니다.';
                } else {
                    $academy_name_sql = sql_escape_string($academy_name);
                    $mb_id_sql = sql_escape_string($mb_id);
                    $service_status_sql = sql_escape_string($service_status);
                    $sms_start_sql = sql_escape_string($sms_start_time);
                    $sms_end_sql = sql_escape_string($sms_end_time);

                    if ($post_academy_id) {
                        sql_query("
                            update " . IEUM_ACADEMY_TABLE . "
                               set academy_code = '{$academy_code_sql}',
                                   mb_id = '{$mb_id_sql}',
                                   academy_name = '{$academy_name_sql}',
                                   gateway_token = '{$gateway_token_sql}',
                                   service_status = '{$service_status_sql}',
                                   sms_start_time = '{$sms_start_sql}',
                                   sms_end_time = '{$sms_end_sql}',
                                   sms_poll_seconds = '{$sms_poll_seconds}',
                                   promotion_interval_months = '{$promotion_interval_months}',
                                   is_active = '{$is_active}',
                                   updated_at = '" . G5_TIME_YMDHIS . "'
                             where academy_id = '{$post_academy_id}'
                        ");
                        $message = '도장 정보가 수정되었습니다.';
                    } else {
                        sql_query("
                            insert into " . IEUM_ACADEMY_TABLE . "
                                set academy_code = '{$academy_code_sql}',
                                    mb_id = '{$mb_id_sql}',
                                    academy_name = '{$academy_name_sql}',
                                    gateway_token = '{$gateway_token_sql}',
                                    service_status = '{$service_status_sql}',
                                    sms_start_time = '{$sms_start_sql}',
                                    sms_end_time = '{$sms_end_sql}',
                                    sms_poll_seconds = '{$sms_poll_seconds}',
                                    promotion_interval_months = '{$promotion_interval_months}',
                                    is_active = '{$is_active}',
                                    created_at = '" . G5_TIME_YMDHIS . "'
                        ");
                        $message = '도장이 등록되었습니다.';
                    }
                    $mode = 'list';
                    $academy_id = 0;
                }
            }
        } elseif ($action === 'toggle') {
            $target = ieum_fetch_academy($post_academy_id);
            if (!$target) {
                $error = '도장 정보를 찾을 수 없습니다.';
            } elseif ((int) $target['academy_id'] === ieum_default_academy_id() && (int) $target['is_active'] === 1) {
                $error = '기본 테스트 도장은 사용중지할 수 없습니다.';
            } else {
                $next_active = $target['is_active'] ? 0 : 1;
                sql_query("
                    update " . IEUM_ACADEMY_TABLE . "
                       set is_active = '{$next_active}',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where academy_id = '{$post_academy_id}'
                ");
                $message = $next_active ? '도장을 사용 상태로 변경했습니다.' : '도장을 사용중지했습니다.';
            }
            $mode = 'list';
            $academy_id = 0;
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$editing = null;
if ($mode === 'form' && $academy_id) {
    $editing = ieum_fetch_academy($academy_id);
    if (!$editing) {
        $mode = 'list';
        $error = '도장 정보를 찾을 수 없습니다.';
    }
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$q_sql = sql_escape_string($q);
$where = " where 1 ";
if ($q !== '') {
    $where .= " and (mb_id like '%{$q_sql}%' or academy_code like '%{$q_sql}%' or academy_name like '%{$q_sql}%' or gateway_token like '%{$q_sql}%' or service_status like '%{$q_sql}%') ";
}

$academies = sql_query("
    select a.*,
           (select count(*) from " . IEUM_STUDENT_TABLE . " s where s.academy_id = a.academy_id) as student_count,
           (select count(*) from " . IEUM_SMS_QUEUE_TABLE . " q where q.academy_id = a.academy_id and q.status = 'pending') as pending_count
      from " . IEUM_ACADEMY_TABLE . " a
      {$where}
  order by a.is_active desc, a.academy_id asc
", false);

$total = sql_fetch("
    select count(*) as cnt
      from " . IEUM_ACADEMY_TABLE . "
      {$where}
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}
.wrap{max-width:1280px;margin:28px auto;padding:0 20px}
.bar{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap}
h1{margin:0;font-size:26px}.count{color:#5b6472}
.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06)}
.notice{margin:0 0 14px;padding:12px 14px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c;border:1px solid #9bd3ad}.err{background:#fdecec;color:#a4262c;border:1px solid #efb2b2}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}
.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}.btn.danger{background:#fff5f5;border-color:#f2b8b8;color:#a4262c}.btn.muted{background:#f1f3f5}
.search{display:flex;gap:8px;align-items:center}.search input{height:38px;border:1px solid #cfd6df;border-radius:6px;padding:0 10px;min-width:280px}
table{width:100%;border-collapse:collapse;background:#fff}th,td{border:1px solid #d8dee9;padding:10px;text-align:center;font-size:14px;vertical-align:top}th{background:#72829d;color:#fff}td.left{text-align:left}.inactive{color:#8a94a6;background:#fafafa}
.form-grid{display:grid;grid-template-columns:170px 1fr;gap:12px 16px;align-items:center;max-width:820px}label{font-weight:700}input[type=text],input[type=number],select{width:100%;border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}
.actions{margin-top:18px;display:flex;gap:8px}form.inline{display:inline}code{background:#eef2f7;border-radius:4px;padding:2px 6px}
@media (max-width:820px){.form-grid{grid-template-columns:1fr}table{display:block;overflow-x:auto;white-space:nowrap}.search{width:100%}.search input{min-width:0;width:100%}}
</style>
</head>
<body>
<?php echo ieum_admin_header('academies'); ?>
<main class="wrap">
    <div class="bar">
        <div>
            <h1>도장 관리</h1>
            <div class="count">총 <?php echo number_format((int) $total['cnt']); ?>개 도장</div>
        </div>
        <div>
            <?php if ($mode === 'form') { ?>
            <a class="btn muted" href="<?php echo IEUM_URL; ?>/admin/academies.php">목록</a>
            <?php } else { ?>
            <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/academies.php?mode=form">도장 등록</a>
            <?php } ?>
        </div>
    </div>

    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <?php if ($mode === 'form') {
        $form = $editing ?: array(
            'academy_id' => 0,
            'mb_id' => '',
            'academy_code' => '',
            'academy_name' => '',
            'gateway_token' => ieum_random_gateway_token(),
            'service_status' => 'pending',
            'sms_start_time' => '10:00',
            'sms_end_time' => '20:00',
            'sms_poll_seconds' => 30,
            'promotion_interval_months' => 3,
            'is_active' => 1,
        );
    ?>
    <section class="panel">
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="academy_id" value="<?php echo (int) $form['academy_id']; ?>">
            <div class="form-grid">
                <label for="mb_id">연결 회원 ID</label>
                <input type="text" name="mb_id" id="mb_id" value="<?php echo get_text($form['mb_id']); ?>" maxlength="50" placeholder="회원가입한 도장 계정 ID">

                <label for="academy_code">도장 코드</label>
                <input type="text" name="academy_code" id="academy_code" value="<?php echo get_text($form['academy_code']); ?>" maxlength="30" placeholder="SEOUL001" required>

                <label for="academy_name">도장명</label>
                <input type="text" name="academy_name" id="academy_name" value="<?php echo get_text($form['academy_name']); ?>" maxlength="100" required>

                <label for="gateway_token">게이트웨이 토큰</label>
                <input type="text" name="gateway_token" id="gateway_token" value="<?php echo get_text($form['gateway_token']); ?>" maxlength="100" required>

                <label for="service_status">아이리포트 상태</label>
                <select name="service_status" id="service_status">
                    <option value="pending" <?php echo get_selected($form['service_status'], 'pending'); ?>>승인대기</option>
                    <option value="active" <?php echo get_selected($form['service_status'], 'active'); ?>>사용중</option>
                    <option value="suspended" <?php echo get_selected($form['service_status'], 'suspended'); ?>>중지</option>
                </select>

                <label for="sms_start_time">문자 시작 시간</label>
                <input type="text" name="sms_start_time" id="sms_start_time" value="<?php echo get_text($form['sms_start_time']); ?>" maxlength="5" placeholder="10:00" required>

                <label for="sms_end_time">문자 종료 시간</label>
                <input type="text" name="sms_end_time" id="sms_end_time" value="<?php echo get_text($form['sms_end_time']); ?>" maxlength="5" placeholder="20:00" required>

                <label for="sms_poll_seconds">확인 주기 초</label>
                <input type="number" name="sms_poll_seconds" id="sms_poll_seconds" value="<?php echo (int) $form['sms_poll_seconds']; ?>" min="15" max="3600" required>

                <label for="promotion_interval_months">승급심사 개월수</label>
                <select name="promotion_interval_months" id="promotion_interval_months">
                    <option value="1" <?php echo get_selected((int) $form['promotion_interval_months'], 1); ?>>1개월</option>
                    <option value="2" <?php echo get_selected((int) $form['promotion_interval_months'], 2); ?>>2개월</option>
                    <option value="3" <?php echo get_selected((int) $form['promotion_interval_months'], 3); ?>>3개월</option>
                </select>

                <label for="is_active">사용 여부</label>
                <div><label><input type="checkbox" name="is_active" id="is_active" value="1" <?php echo $form['is_active'] ? 'checked' : ''; ?>> 사용</label></div>
            </div>
            <div class="actions">
                <button class="btn primary" type="submit">저장</button>
                <a class="btn muted" href="<?php echo IEUM_URL; ?>/admin/academies.php">취소</a>
            </div>
        </form>
    </section>
    <?php } else { ?>
    <section class="panel">
        <div class="bar">
            <form method="get" class="search">
                <input type="text" name="q" value="<?php echo get_text($q); ?>" placeholder="회원 ID, 도장 코드, 도장명, 상태 검색">
                <button type="submit" class="btn">검색</button>
                <?php if ($q !== '') { ?><a class="btn muted" href="<?php echo IEUM_URL; ?>/admin/academies.php">전체</a><?php } ?>
            </form>
        </div>
        <table>
            <thead>
            <tr>
                <th scope="col">상태</th>
                <th scope="col">회원 ID</th>
                <th scope="col">코드</th>
                <th scope="col">도장명</th>
                <th scope="col">아이리포트</th>
                <th scope="col">토큰</th>
                <th scope="col">운영 시간</th>
                <th scope="col">주기</th>
                <th scope="col">승급</th>
                <th scope="col">학생</th>
                <th scope="col">문자 대기</th>
                <th scope="col">관리</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $i = 0;
            while ($row = sql_fetch_array($academies)) {
                $i++;
                $row_class = $row['is_active'] ? '' : 'inactive';
            ?>
            <tr class="<?php echo $row_class; ?>">
                <td><?php echo $row['is_active'] ? '사용' : '중지'; ?></td>
                <td><?php echo get_text($row['mb_id']); ?></td>
                <td><?php echo get_text($row['academy_code']); ?></td>
                <td><?php echo get_text($row['academy_name']); ?></td>
                <td><?php echo get_text($row['service_status']); ?></td>
                <td class="left"><code><?php echo get_text($row['gateway_token']); ?></code></td>
                <td><?php echo get_text($row['sms_start_time'] . ' ~ ' . $row['sms_end_time']); ?></td>
                <td><?php echo number_format((int) $row['sms_poll_seconds']); ?>초</td>
                <td><?php echo number_format((int) $row['promotion_interval_months']); ?>개월</td>
                <td><?php echo number_format((int) $row['student_count']); ?>명</td>
                <td><?php echo number_format((int) $row['pending_count']); ?>건</td>
                <td>
                    <a class="btn" href="<?php echo IEUM_URL; ?>/admin/academies.php?mode=form&amp;academy_id=<?php echo (int) $row['academy_id']; ?>">수정</a>
                    <form method="post" class="inline" onsubmit="return confirm('<?php echo $row['is_active'] ? '이 도장을 사용중지할까요?' : '이 도장을 다시 사용 상태로 바꿀까요?'; ?>');">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="academy_id" value="<?php echo (int) $row['academy_id']; ?>">
                        <button type="submit" class="btn <?php echo $row['is_active'] ? 'danger' : 'muted'; ?>"><?php echo $row['is_active'] ? '중지' : '사용'; ?></button>
                    </form>
                </td>
            </tr>
            <?php } ?>
            <?php if ($i === 0) { ?>
            <tr><td colspan="12">등록된 도장이 없습니다.</td></tr>
            <?php } ?>
            </tbody>
        </table>
    </section>
    <?php } ?>
</main>
</body>
</html>
