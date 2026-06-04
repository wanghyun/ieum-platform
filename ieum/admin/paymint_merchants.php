<?php
$sub_menu = '950187';
require_once './_common.php';
require_once IEUM_PATH . '/lib/paymint.php';

if ($is_admin !== 'super') {
    alert('본사 관리자만 접근할 수 있습니다.');
}

$g5['title'] = '도장별 결제선생 연동';
ieum_paymint_ensure_tables();

$message = '';
$error = '';

function ieum_paymint_merchant_status_options()
{
    return array(
        'not_ready' => '준비 필요',
        'pending' => '등록 대기',
        'mapped' => '연동 완료',
        'failed' => '확인 필요',
        'paused' => '중지',
    );
}

function ieum_paymint_merchant_status_label($status)
{
    $options = ieum_paymint_merchant_status_options();
    return isset($options[$status]) ? $options[$status] : '준비 필요';
}

function ieum_paymint_merchant_status_class($status)
{
    if ($status === 'mapped') {
        return 'good';
    }
    if ($status === 'failed') {
        return 'danger';
    }
    if ($status === 'pending') {
        return 'warn';
    }
    if ($status === 'paused') {
        return 'muted';
    }
    return 'need';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다. 새로고침 후 다시 시도하세요.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        $academy_id = isset($_POST['academy_id']) ? (int) $_POST['academy_id'] : 0;

        if ($action === 'sync_mapping_list') {
            $result = ieum_paymint_sync_mapping_list();
            if (!empty($result['ok'])) {
                $message = '결제선생 도장 연동 상태를 동기화했습니다. 반영 ' . number_format((int) $result['updated']) . '건';
            } else {
                $error = '결제선생 연동 상태 동기화에 실패했습니다. ' . (isset($result['message']) ? $result['message'] : '');
            }
        } else {
            $academy = $academy_id ? sql_fetch("
                select academy_id, academy_name
                  from " . IEUM_ACADEMY_TABLE . "
                 where academy_id = '{$academy_id}'
                 limit 1
            ", false) : null;

            if (!$academy || !isset($academy['academy_id'])) {
                $error = '도장 정보를 찾을 수 없습니다.';
            } elseif ($action === 'save_merchant') {
                $member_id = isset($_POST['member_id']) ? trim($_POST['member_id']) : '';
                $merchant_id = isset($_POST['merchant_id']) ? trim($_POST['merchant_id']) : '';
                $business_number = isset($_POST['business_number']) ? preg_replace('/[^0-9-]/', '', trim($_POST['business_number'])) : '';
                $mapping_status = isset($_POST['mapping_status']) ? trim($_POST['mapping_status']) : 'not_ready';
                $status_options = ieum_paymint_merchant_status_options();
                if (!isset($status_options[$mapping_status])) {
                    $mapping_status = 'not_ready';
                }

                $mapped_at_sql = $mapping_status === 'mapped' ? "mapped_at = if(mapped_at is null, '" . G5_TIME_YMDHIS . "', mapped_at)," : '';
                if ($mapping_status !== 'mapped') {
                    $mapped_at_sql = "mapped_at = null,";
                }

                sql_query("
                    insert into " . IEUM_PAYMINT_MERCHANT_TABLE . "
                        set academy_id = '{$academy_id}',
                            member_id = '" . sql_escape_string($member_id) . "',
                            merchant_id = '" . sql_escape_string($merchant_id) . "',
                            business_number = '" . sql_escape_string($business_number) . "',
                            mapping_status = '" . sql_escape_string($mapping_status) . "',
                            mapped_at = " . ($mapping_status === 'mapped' ? "'" . G5_TIME_YMDHIS . "'" : "null") . ",
                            created_at = '" . G5_TIME_YMDHIS . "',
                            updated_at = '" . G5_TIME_YMDHIS . "'
                    on duplicate key update
                            member_id = values(member_id),
                            merchant_id = values(merchant_id),
                            business_number = values(business_number),
                            mapping_status = values(mapping_status),
                            {$mapped_at_sql}
                            updated_at = values(updated_at)
                ");
                $message = get_text($academy['academy_name']) . ' 결제선생 준비 상태를 저장했습니다.';
            } elseif ($action === 'create_mapping_url') {
                $result = ieum_paymint_create_mapping_url($academy_id, isset($member['mb_id']) ? $member['mb_id'] : '');
                if (!empty($result['ok'])) {
                    $message = get_text($academy['academy_name']) . ' 결제선생 가입/연동 URL을 생성했습니다.';
                } else {
                    $error = get_text($academy['academy_name']) . ' 결제선생 가입/연동 URL 생성에 실패했습니다. ' . (isset($result['message']) ? $result['message'] : '');
                }
            } elseif ($action === 'refresh_merchant_balance') {
                $result = ieum_paymint_read_merchant_balance($academy_id);
                if (!empty($result['ok'])) {
                    $message = get_text($academy['academy_name']) . ' 도장 쌤포인트 잔액을 확인했습니다.';
                } else {
                    $error = get_text($academy['academy_name']) . ' 도장 쌤포인트 확인에 실패했습니다. ' . (isset($result['message']) ? $result['message'] : '');
                }
            }
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$status_options = ieum_paymint_merchant_status_options();
if ($status !== '' && !isset($status_options[$status])) {
    $status = '';
}

$where = " where 1 ";
if ($q !== '') {
    $q_sql = sql_escape_string($q);
    $where .= " and (a.academy_name like '%{$q_sql}%' or a.academy_code like '%{$q_sql}%' or a.mb_id like '%{$q_sql}%' or pm.member_id like '%{$q_sql}%' or pm.merchant_id like '%{$q_sql}%' or pm.business_number like '%{$q_sql}%') ";
}
if ($status !== '') {
    if ($status === 'not_ready') {
        $where .= " and (pm.mapping_status is null or pm.mapping_status = '' or pm.mapping_status = 'not_ready') ";
    } else {
        $where .= " and pm.mapping_status = '" . sql_escape_string($status) . "' ";
    }
}

$summary = array('total' => 0, 'not_ready' => 0, 'pending' => 0, 'mapped' => 0, 'failed' => 0, 'paused' => 0);
$summary_result = sql_query("
    select coalesce(nullif(pm.mapping_status, ''), 'not_ready') as mapping_status,
           count(*) as cnt
      from " . IEUM_ACADEMY_TABLE . " a
 left join " . IEUM_PAYMINT_MERCHANT_TABLE . " pm on pm.academy_id = a.academy_id
  group by coalesce(nullif(pm.mapping_status, ''), 'not_ready')
", false);
while ($row = sql_fetch_array($summary_result)) {
    $key = isset($summary[$row['mapping_status']]) ? $row['mapping_status'] : 'not_ready';
    $summary[$key] += (int) $row['cnt'];
    $summary['total'] += (int) $row['cnt'];
}

$academies = sql_query("
    select a.academy_id,
           a.academy_code,
           a.mb_id,
           a.academy_name,
           a.service_status,
           a.is_active,
           pm.member_id as paymint_member_id,
           pm.merchant_id as paymint_merchant_id,
           pm.business_number,
           pm.mapping_status,
           pm.mapping_url,
           pm.mapping_status_raw,
           pm.remote_balance,
           pm.charge_url,
           pm.last_sync_at,
           pm.last_error,
           pm.mapped_at,
           pm.updated_at,
           (select count(*) from " . IEUM_STUDENT_TABLE . " s where s.academy_id = a.academy_id and s.is_active = 1) as active_students,
           (select count(*) from " . IEUM_PAYMINT_BILL_TABLE . " pb where pb.academy_id = a.academy_id) as bill_count
      from " . IEUM_ACADEMY_TABLE . " a
 left join " . IEUM_PAYMINT_MERCHANT_TABLE . " pm on pm.academy_id = a.academy_id
      {$where}
  order by field(coalesce(nullif(pm.mapping_status, ''), 'not_ready'), 'failed', 'not_ready', 'pending', 'mapped', 'paused'),
           a.is_active desc,
           a.academy_name asc,
           a.academy_id asc
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}.hero,.panel{background:#fff;border:1px solid #d9dee7;border-radius:10px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}.bar{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}h1{margin:0 0 8px;font-size:30px}.meta{color:#667085;font-size:13px;line-height:1.5}.notice{padding:12px 14px;border-radius:8px;margin:12px 0}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.summary{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-top:16px}.stat{border:1px solid #d9e2f1;border-radius:10px;background:#fbfcff;padding:14px}.stat span{display:block;color:#667085;font-size:13px;font-weight:800}.stat strong{display:block;font-size:26px;margin-top:4px}.filters,.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}input,select{border:1px solid #cfd6df;border-radius:8px;padding:10px;font-size:14px;background:#fff}.filters input{min-width:260px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.soft{background:#f1f6ff;color:#174a8b}.table-wrap{overflow-x:auto}table{width:100%;min-width:1500px;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center;vertical-align:top}th{background:#71829f;color:#fff}.left{text-align:left}.merchant-form{display:grid;grid-template-columns:1.1fr 1.1fr .9fr .9fr auto;gap:8px;align-items:center}.badge{display:inline-flex;align-items:center;justify-content:center;min-width:76px;border-radius:999px;padding:6px 10px;font-weight:900;font-size:13px}.good{background:#eaf8ef;color:#176b2c}.warn{background:#fff7e6;color:#9a5b00}.danger{background:#fdecec;color:#a4262c}.need{background:#eef2ff;color:#2447a6}.muted{background:#eef1f5;color:#475467}.guide{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.guide article{border:1px solid #d9e2f1;border-radius:10px;background:#fbfcff;padding:15px}.guide strong{display:block;font-size:17px;margin-bottom:5px}.guide p{margin:0;color:#667085;line-height:1.5}.code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:3px 6px}.inline-form{display:inline-flex;margin:2px}.url-box{word-break:break-all;max-width:360px}@media(max-width:1000px){.summary,.guide{grid-template-columns:1fr 1fr}.merchant-form{grid-template-columns:1fr 1fr}.filters input{min-width:0;width:100%}}@media(max-width:700px){.summary,.guide,.merchant-form{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php echo ieum_admin_header('paymint_merchants'); ?>
<?php echo ieum_admin_subnav('paymint_merchants'); ?>
<main class="wrap">
    <section class="hero">
        <div class="bar">
            <div>
                <h1>도장별 결제선생 연동</h1>
                <div class="meta">본사에서 각 도장의 결제 계정 준비, 가입 URL, 연동 상태를 확인합니다. 도장 관리자에게는 청구서 발송 단가와 본사 쌤포인트 잔액이 노출되지 않습니다.</div>
            </div>
            <div class="actions">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
                    <input type="hidden" name="action" value="sync_mapping_list">
                    <button type="submit" class="btn primary">결제선생 상태 동기화</button>
                </form>
                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/paymint_settings.php">결제선생 설정</a>
                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/billing_wallet.php">본사 결제 운영</a>
            </div>
        </div>
        <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
        <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>
        <div class="summary">
            <div class="stat"><span>전체 도장</span><strong><?php echo number_format($summary['total']); ?></strong></div>
            <div class="stat"><span>준비 필요</span><strong><?php echo number_format($summary['not_ready']); ?></strong></div>
            <div class="stat"><span>등록 대기</span><strong><?php echo number_format($summary['pending']); ?></strong></div>
            <div class="stat"><span>연동 완료</span><strong><?php echo number_format($summary['mapped']); ?></strong></div>
            <div class="stat"><span>확인 필요</span><strong><?php echo number_format($summary['failed']); ?></strong></div>
            <div class="stat"><span>중지</span><strong><?php echo number_format($summary['paused']); ?></strong></div>
        </div>
    </section>

    <section class="panel">
        <div class="guide">
            <article><strong>1. 도장 정보 확인</strong><p>사업자번호와 도장명을 확인하고 가입 URL을 생성합니다.</p></article>
            <article><strong>2. 도장 결제 계정 연동</strong><p>결제선생 가입/연동이 완료되면 Member ID와 Merchant ID가 기록됩니다.</p></article>
            <article><strong>3. 청구서 발송 준비</strong><p>연동 완료 도장만 수련비 청구서 발송 대상이 됩니다.</p></article>
        </div>
    </section>

    <section class="panel">
        <form method="get" class="filters">
            <select name="status">
                <option value="">전체 상태</option>
                <?php foreach ($status_options as $key => $label) { ?>
                    <option value="<?php echo get_text($key); ?>" <?php echo get_selected($status, $key); ?>><?php echo get_text($label); ?></option>
                <?php } ?>
            </select>
            <input type="text" name="q" value="<?php echo get_text($q); ?>" placeholder="도장명, 도장코드, 회원ID, 사업자번호, 결제선생 ID 검색">
            <button type="submit" class="btn primary">조회</button>
            <?php if ($q !== '' || $status !== '') { ?><a class="btn" href="<?php echo IEUM_URL; ?>/admin/paymint_merchants.php">초기화</a><?php } ?>
        </form>
    </section>

    <section class="panel">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>상태</th>
                    <th>도장</th>
                    <th>회원 ID</th>
                    <th>원생</th>
                    <th>결제선생 연동값</th>
                    <th>가입 URL / API 확인</th>
                    <th>청구서</th>
                    <th>최근 변경</th>
                </tr>
                </thead>
                <tbody>
                <?php $i = 0; while ($row = sql_fetch_array($academies)) { $i++; $row_status = $row['mapping_status'] ? $row['mapping_status'] : 'not_ready'; ?>
                <tr>
                    <td><span class="badge <?php echo ieum_paymint_merchant_status_class($row_status); ?>"><?php echo get_text(ieum_paymint_merchant_status_label($row_status)); ?></span></td>
                    <td class="left">
                        <strong><?php echo get_text($row['academy_name']); ?></strong>
                        <div class="meta"><?php echo get_text($row['academy_code']); ?> · <?php echo $row['is_active'] ? '사용' : '중지'; ?> · <?php echo get_text($row['service_status']); ?></div>
                    </td>
                    <td><?php echo get_text($row['mb_id'] ?: '-'); ?></td>
                    <td><?php echo number_format((int) $row['active_students']); ?>명</td>
                    <td class="left">
                        <form method="post" class="merchant-form">
                            <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
                            <input type="hidden" name="action" value="save_merchant">
                            <input type="hidden" name="academy_id" value="<?php echo (int) $row['academy_id']; ?>">
                            <input type="text" name="member_id" value="<?php echo get_text($row['paymint_member_id']); ?>" placeholder="Member ID">
                            <input type="text" name="merchant_id" value="<?php echo get_text($row['paymint_merchant_id']); ?>" placeholder="Merchant ID">
                            <input type="text" name="business_number" value="<?php echo get_text($row['business_number']); ?>" placeholder="사업자번호">
                            <select name="mapping_status">
                                <?php foreach ($status_options as $key => $label) { ?>
                                    <option value="<?php echo get_text($key); ?>" <?php echo get_selected($row_status, $key); ?>><?php echo get_text($label); ?></option>
                                <?php } ?>
                            </select>
                            <button type="submit" class="btn primary">저장</button>
                        </form>
                    </td>
                    <td class="left">
                        <div class="actions">
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
                                <input type="hidden" name="action" value="create_mapping_url">
                                <input type="hidden" name="academy_id" value="<?php echo (int) $row['academy_id']; ?>">
                                <button type="submit" class="btn soft">가입 URL 생성</button>
                            </form>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
                                <input type="hidden" name="action" value="refresh_merchant_balance">
                                <input type="hidden" name="academy_id" value="<?php echo (int) $row['academy_id']; ?>">
                                <button type="submit" class="btn">도장 잔액 확인</button>
                            </form>
                        </div>
                        <?php if (!empty($row['mapping_url'])) { ?>
                            <div class="url-box"><a href="<?php echo get_text($row['mapping_url']); ?>" target="_blank" rel="noopener"><?php echo get_text($row['mapping_url']); ?></a></div>
                        <?php } ?>
                        <?php if ((int) $row['remote_balance'] >= 0) { ?><div class="meta">도장 잔액 <?php echo number_format((int) $row['remote_balance']); ?>P</div><?php } ?>
                        <?php if (!empty($row['last_error'])) { ?><div class="meta">최근 오류: <?php echo get_text($row['last_error']); ?></div><?php } ?>
                    </td>
                    <td><?php echo number_format((int) $row['bill_count']); ?>건</td>
                    <td>
                        <?php if ($row['mapped_at']) { ?><div>완료 <?php echo get_text(substr($row['mapped_at'], 0, 10)); ?></div><?php } ?>
                        <div class="meta">수정 <?php echo get_text($row['updated_at'] ? substr($row['updated_at'], 0, 16) : '-'); ?></div>
                        <div class="meta">동기화 <?php echo get_text($row['last_sync_at'] ? substr($row['last_sync_at'], 0, 16) : '-'); ?></div>
                    </td>
                </tr>
                <?php } ?>
                <?php if ($i === 0) { ?>
                <tr><td colspan="8">조회된 도장이 없습니다.</td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
