<?php
$sub_menu = '950186';
require_once './_common.php';
require_once IEUM_PATH . '/lib/paymint.php';

if ($is_admin !== 'super') {
    alert('본사 관리자만 접근할 수 있습니다.');
}

$g5['title'] = '결제선생 연동 설정';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다. 새로고침 후 다시 시도하세요.';
    } else {
        $current = ieum_paymint_get_settings();
        $environment = isset($_POST['environment']) && $_POST['environment'] === 'production' ? 'production' : 'sandbox';
        $partner_mode = 'partner_managed';
        $partner_member_id = isset($_POST['partner_member_id']) ? trim($_POST['partner_member_id']) : '';
        $partner_merchant_id = isset($_POST['partner_merchant_id']) ? trim($_POST['partner_merchant_id']) : '';
        $api_key = isset($_POST['api_key']) ? trim($_POST['api_key']) : '';
        $api_secret = isset($_POST['api_secret']) ? trim($_POST['api_secret']) : '';
        $callback_token = isset($_POST['callback_token']) ? trim($_POST['callback_token']) : '';
        $callback_base_url = isset($_POST['callback_base_url']) ? rtrim(trim($_POST['callback_base_url']), '/') : '';
        $default_send_type = isset($_POST['default_send_type']) && $_POST['default_send_type'] === 'URL' ? 'URL' : 'TALK';
        $resend_cooldown_hours = isset($_POST['resend_cooldown_hours']) ? max(0, (int) $_POST['resend_cooldown_hours']) : 24;
        $low_balance_threshold = isset($_POST['low_balance_threshold']) ? max(0, (int) $_POST['low_balance_threshold']) : 5000;
        $monthly_send_cap_per_academy = isset($_POST['monthly_send_cap_per_academy']) ? max(0, (int) $_POST['monthly_send_cap_per_academy']) : 0;
        $bill_expire_days = isset($_POST['bill_expire_days']) ? min(60, max(1, (int) $_POST['bill_expire_days'])) : 7;
        $memo = isset($_POST['memo']) ? trim($_POST['memo']) : '';

        if ($api_secret === '' && !empty($current['api_secret'])) {
            $api_secret = $current['api_secret'];
        }
        if ($callback_token === '') {
            $callback_token = !empty($current['callback_token']) ? $current['callback_token'] : md5(uniqid('paymint', true));
        }
        if ($callback_base_url !== '' && !preg_match('#^https?://#i', $callback_base_url)) {
            $error = '서비스 기준 URL은 http:// 또는 https:// 로 시작해야 합니다.';
        }

        if ($error === '') {
            sql_query("
            insert into " . IEUM_PAYMINT_SETTING_TABLE . "
                set setting_id = 1,
                    environment = '" . sql_escape_string($environment) . "',
                    partner_mode = '" . sql_escape_string($partner_mode) . "',
                    partner_member_id = '" . sql_escape_string($partner_member_id) . "',
                    partner_merchant_id = '" . sql_escape_string($partner_merchant_id) . "',
                    api_key = '" . sql_escape_string($api_key) . "',
                    api_secret = '" . sql_escape_string($api_secret) . "',
                    callback_token = '" . sql_escape_string($callback_token) . "',
                    callback_base_url = '" . sql_escape_string($callback_base_url) . "',
                    default_send_type = '" . sql_escape_string($default_send_type) . "',
                    use_url_mode_for_test = '" . (isset($_POST['use_url_mode_for_test']) ? 1 : 0) . "',
                    prevent_duplicate_bill = '" . (isset($_POST['prevent_duplicate_bill']) ? 1 : 0) . "',
                    exclude_paid_students = '" . (isset($_POST['exclude_paid_students']) ? 1 : 0) . "',
                    include_arrears_default = '" . (isset($_POST['include_arrears_default']) ? 1 : 0) . "',
                    resend_cooldown_hours = '{$resend_cooldown_hours}',
                    low_balance_threshold = '{$low_balance_threshold}',
                    monthly_send_cap_per_academy = '{$monthly_send_cap_per_academy}',
                    bill_expire_days = '{$bill_expire_days}',
                    memo = '" . sql_escape_string($memo) . "',
                    created_at = '" . G5_TIME_YMDHIS . "',
                    updated_at = '" . G5_TIME_YMDHIS . "'
            on duplicate key update
                    environment = values(environment),
                    partner_mode = values(partner_mode),
                    partner_member_id = values(partner_member_id),
                    partner_merchant_id = values(partner_merchant_id),
                    api_key = values(api_key),
                    api_secret = values(api_secret),
                    callback_token = values(callback_token),
                    callback_base_url = values(callback_base_url),
                    default_send_type = values(default_send_type),
                    use_url_mode_for_test = values(use_url_mode_for_test),
                    prevent_duplicate_bill = values(prevent_duplicate_bill),
                    exclude_paid_students = values(exclude_paid_students),
                    include_arrears_default = values(include_arrears_default),
                    resend_cooldown_hours = values(resend_cooldown_hours),
                    low_balance_threshold = values(low_balance_threshold),
                    monthly_send_cap_per_academy = values(monthly_send_cap_per_academy),
                    bill_expire_days = values(bill_expire_days),
                    memo = values(memo),
                    updated_at = values(updated_at)
        ");
        $message = '결제선생 연동 설정을 저장했습니다.';
        }
    }
}

$settings = ieum_paymint_get_settings();
$csrf_token = ieum_new_csrf_token();
$service_base_url = ieum_paymint_service_base_url($settings);
$callback_url = $service_base_url . '/api/paymint/payment_callback.php?token=' . rawurlencode($settings['callback_token']);
$merchant_callback_url = $service_base_url . '/api/paymint/merchant_callback.php?token=' . rawurlencode($settings['callback_token']);
$api_base_url = ieum_paymint_base_url($settings);
$has_secret = !empty($settings['api_secret']);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}.hero,.panel{background:#fff;border:1px solid #d9dee7;border-radius:10px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}h1{margin:0 0 8px;font-size:30px}.meta{color:#667085}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field{display:grid;gap:7px}.field label{font-weight:900;color:#344054}input,select,textarea{width:100%;border:1px solid #cfd6df;border-radius:8px;padding:11px;font-size:15px;background:#fff}textarea{min-height:82px;resize:vertical}.checks{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.check{border:1px solid #d9e2f1;border-radius:8px;background:#fbfcff;padding:12px;display:flex;gap:8px;align-items:flex-start}.check strong{display:block}.check small{display:block;color:#667085;margin-top:4px;line-height:1.45}.notice{padding:12px 14px;border-radius:8px;margin:12px 0}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.hint{border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;padding:14px;color:#344054;line-height:1.65}.warn{border-color:#fed7aa;background:#fff7ed}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:9px 14px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}.pill{display:inline-flex;align-items:center;border-radius:999px;background:#eef5ff;color:#1769c2;padding:6px 10px;font-weight:900}.code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:7px 9px;word-break:break-all}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px}.card strong{display:block;font-size:18px;margin-bottom:6px}@media(max-width:900px){.grid,.checks,.cards{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php echo ieum_admin_header('paymint_settings'); ?>
<?php echo ieum_admin_subnav('paymint_settings'); ?>
<main class="wrap">
    <section class="hero">
        <h1>결제선생 연동 설정</h1>
        <div class="meta">본사 전용 · 현재 환경 <span class="pill"><?php echo get_text($settings['environment'] === 'production' ? '운영' : '샌드박스'); ?></span> · API Base <span class="code"><?php echo get_text($api_base_url); ?></span></div>
        <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
        <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>
    </section>

    <section class="panel">
        <h2>연동 방향</h2>
        <div class="cards">
            <article class="card"><strong>본사 일괄 관리</strong><span>아이이음이 파트너로 계약하고 각 도장은 결제 계정으로 연결합니다.</span></article>
            <article class="card"><strong>도장에는 무료처럼 보이기</strong><span>발송 비용과 포인트 잔액은 본사만 보고, 도장은 청구/수납 상태만 봅니다.</span></article>
            <article class="card"><strong>비용 누수 방지</strong><span>완납자 제외, 중복 방지, 재발송 쿨타임, 잔액 경고를 기본값으로 둡니다.</span></article>
        </div>
    </section>

    <form method="post" class="panel">
        <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
        <h2>API 정보</h2>
        <div class="grid">
            <div class="field">
                <label for="environment">환경</label>
                <select name="environment" id="environment">
                    <option value="sandbox" <?php echo $settings['environment'] === 'sandbox' ? 'selected' : ''; ?>>샌드박스</option>
                    <option value="production" <?php echo $settings['environment'] === 'production' ? 'selected' : ''; ?>>운영</option>
                </select>
            </div>
            <div class="field">
                <label>운영 방식</label>
                <input type="text" value="파트너 일괄 관리" readonly>
            </div>
            <div class="field">
                <label for="partner_member_id">파트너 Member ID</label>
                <input type="text" name="partner_member_id" id="partner_member_id" value="<?php echo get_text($settings['partner_member_id']); ?>" maxlength="80" placeholder="제휴 후 발급받은 본사 회원 ID">
            </div>
            <div class="field">
                <label for="partner_merchant_id">파트너 Merchant ID</label>
                <input type="text" name="partner_merchant_id" id="partner_merchant_id" value="<?php echo get_text($settings['partner_merchant_id']); ?>" maxlength="80" placeholder="제휴 후 발급받은 본사 가맹점 ID">
            </div>
            <div class="field">
                <label for="api_key">API Key</label>
                <input type="text" name="api_key" id="api_key" value="<?php echo get_text($settings['api_key']); ?>" maxlength="160" placeholder="제휴 후 발급">
            </div>
            <div class="field">
                <label for="api_secret">API Secret <?php if ($has_secret) { ?><span class="meta">저장됨: <?php echo get_text(ieum_paymint_mask_secret($settings['api_secret'])); ?></span><?php } ?></label>
                <input type="password" name="api_secret" id="api_secret" value="" maxlength="160" placeholder="<?php echo $has_secret ? '변경할 때만 새 Secret 입력' : '제휴 후 발급'; ?>">
            </div>
            <div class="field" style="grid-column:1/-1">
                <label for="callback_token">콜백 검증 토큰</label>
                <input type="text" name="callback_token" id="callback_token" value="<?php echo get_text($settings['callback_token']); ?>" maxlength="120">
            </div>
            <div class="field" style="grid-column:1/-1">
                <label for="callback_base_url">서비스 기준 URL</label>
                <input type="text" name="callback_base_url" id="callback_base_url" value="<?php echo get_text(isset($settings['callback_base_url']) ? $settings['callback_base_url'] : ''); ?>" maxlength="255" placeholder="예: https://apps.i-eum.co.kr/ieum">
                <small class="meta">자동발송/크론에서 콜백과 결제 후 이동 URL을 만들 때 사용합니다. 로컬 테스트는 http://localhost/ieum, 운영은 실제 도메인을 입력합니다.</small>
            </div>
        </div>

        <h2 style="margin-top:24px">비용 절감 정책</h2>
        <div class="checks">
            <label class="check"><input type="checkbox" name="exclude_paid_students" value="1" <?php echo !empty($settings['exclude_paid_students']) ? 'checked' : ''; ?>> <span><strong>완납자 자동 제외</strong><small>미리 납부한 학생에게는 청구서를 만들지 않습니다.</small></span></label>
            <label class="check"><input type="checkbox" name="prevent_duplicate_bill" value="1" <?php echo !empty($settings['prevent_duplicate_bill']) ? 'checked' : ''; ?>> <span><strong>월별 중복 방지</strong><small>같은 학생, 같은 월 청구서는 1회만 생성합니다.</small></span></label>
            <label class="check"><input type="checkbox" name="include_arrears_default" value="1" <?php echo !empty($settings['include_arrears_default']) ? 'checked' : ''; ?>> <span><strong>이전 미납 합산</strong><small>여러 번 보내지 않고 이번 달 청구서에 합쳐 보냅니다.</small></span></label>
            <label class="check"><input type="checkbox" name="use_url_mode_for_test" value="1" <?php echo !empty($settings['use_url_mode_for_test']) ? 'checked' : ''; ?>> <span><strong>테스트 URL 모드</strong><small>개발/검수 중에는 알림톡 대신 URL 발급만 사용합니다.</small></span></label>
        </div>
        <div class="grid" style="margin-top:14px">
            <div class="field">
                <label for="default_send_type">기본 발송 방식</label>
                <select name="default_send_type" id="default_send_type">
                    <option value="TALK" <?php echo $settings['default_send_type'] === 'TALK' ? 'selected' : ''; ?>>카카오 청구서 발송</option>
                    <option value="URL" <?php echo $settings['default_send_type'] === 'URL' ? 'selected' : ''; ?>>URL 생성만</option>
                </select>
            </div>
            <div class="field">
                <label for="resend_cooldown_hours">재발송 제한 시간</label>
                <input type="number" name="resend_cooldown_hours" id="resend_cooldown_hours" value="<?php echo (int) $settings['resend_cooldown_hours']; ?>" min="0"> 
            </div>
            <div class="field">
                <label for="low_balance_threshold">본사 잔액 경고 기준</label>
                <input type="number" name="low_balance_threshold" id="low_balance_threshold" value="<?php echo (int) $settings['low_balance_threshold']; ?>" min="0">
            </div>
            <div class="field">
                <label for="monthly_send_cap_per_academy">도장별 월 발송 상한</label>
                <input type="number" name="monthly_send_cap_per_academy" id="monthly_send_cap_per_academy" value="<?php echo (int) $settings['monthly_send_cap_per_academy']; ?>" min="0">
            </div>
            <div class="field">
                <label for="bill_expire_days">청구서 유효기간</label>
                <input type="number" name="bill_expire_days" id="bill_expire_days" value="<?php echo (int) $settings['bill_expire_days']; ?>" min="1" max="60">
            </div>
            <div class="field">
                <label for="memo">운영 메모</label>
                <textarea name="memo" id="memo" maxlength="255" placeholder="제휴 담당자, 단가, 운영 주의사항"><?php echo get_text($settings['memo']); ?></textarea>
            </div>
        </div>

        <h2 style="margin-top:24px">콜백 URL</h2>
        <div class="hint">
            결제 완료 콜백: <div class="code"><?php echo get_text($callback_url); ?></div>
            도장 결제 계정 등록 콜백: <div class="code"><?php echo get_text($merchant_callback_url); ?></div>
        </div>
        <div class="hint warn" style="margin-top:12px">
            결제선생 문서의 hash 생성 규칙은 “조합 문자열”만 명시되어 있고 실제 암호화 방식은 제휴 발급 자료에서 확인해야 합니다. API 키를 받은 뒤 이 화면의 설정을 기준으로 실제 발송 함수를 연결합니다.
        </div>

        <div class="actions">
            <button type="submit" class="btn primary">설정 저장</button>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/billing_wallet.php">본사 청구 사용량</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/paymint_merchants.php">도장별 연동 상태</a>
            <a class="btn" href="https://developers.payssam.kr/understanding/introduce" target="_blank" rel="noopener">결제선생 개발문서</a>
        </div>
    </form>
</main>
</body>
</html>
