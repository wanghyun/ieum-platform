<?php
$sub_menu = '950148';
require_once './_common.php';
require_once IEUM_PATH . '/lib/maps.php';

$g5['title'] = '아이이음 지도 API 설정';
ieum_require_head_admin_page();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다. 새로고침 후 다시 시도하세요.';
    } else {
        $current = ieum_map_get_system_settings();
        $use_dynamic_map = isset($_POST['use_dynamic_map']) ? 1 : 0;
        $use_geocoding = isset($_POST['use_geocoding']) ? 1 : 0;
        $use_directions = isset($_POST['use_directions']) ? 1 : 0;
        $client_id = isset($_POST['naver_client_id']) ? trim($_POST['naver_client_id']) : '';
        $client_secret = isset($_POST['naver_client_secret']) ? trim($_POST['naver_client_secret']) : '';
        $web_service_url = isset($_POST['naver_web_service_url']) ? trim($_POST['naver_web_service_url']) : '';
        $memo = isset($_POST['memo']) ? trim($_POST['memo']) : '';

        if ($client_secret === '' && !empty($current['naver_client_secret'])) {
            $client_secret = $current['naver_client_secret'];
        }

        $client_id_sql = sql_escape_string($client_id);
        $client_secret_sql = sql_escape_string($client_secret);
        $web_service_url_sql = sql_escape_string($web_service_url);
        $memo_sql = sql_escape_string($memo);
        $system_id = IEUM_MAP_SYSTEM_ACADEMY_ID;

        sql_query("
            insert into " . IEUM_MAP_SETTING_TABLE . "
                set academy_id = '{$system_id}',
                    provider = 'naver',
                    use_dynamic_map = '{$use_dynamic_map}',
                    use_geocoding = '{$use_geocoding}',
                    use_directions = '{$use_directions}',
                    naver_client_id = '{$client_id_sql}',
                    naver_client_secret = '{$client_secret_sql}',
                    naver_web_service_url = '{$web_service_url_sql}',
                    memo = '{$memo_sql}',
                    updated_at = '" . G5_TIME_YMDHIS . "'
            on duplicate key update
                    use_dynamic_map = values(use_dynamic_map),
                    use_geocoding = values(use_geocoding),
                    use_directions = values(use_directions),
                    naver_client_id = values(naver_client_id),
                    naver_client_secret = values(naver_client_secret),
                    naver_web_service_url = values(naver_web_service_url),
                    memo = values(memo),
                    updated_at = values(updated_at)
        ");

        $message = '지도 API 설정을 저장했습니다.';
    }
}

$settings = ieum_map_get_system_settings();
$csrf_token = ieum_new_csrf_token();
$has_secret = ieum_map_has_secret($settings);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}h1{margin:0 0 8px;font-size:28px}.meta{color:#667085;margin-bottom:16px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field{display:grid;gap:7px}.field label{font-weight:900;color:#344054}input[type=text],input[type=password],textarea{width:100%;border:1px solid #cfd6df;border-radius:8px;padding:11px;font-size:15px}textarea{min-height:86px;resize:vertical}.checks{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.check{display:flex;gap:8px;align-items:flex-start;border:1px solid #d9e2f1;border-radius:8px;background:#fbfcff;padding:12px;font-weight:900}.check small{display:block;color:#667085;font-weight:700;margin-top:4px;line-height:1.45}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:9px 14px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.hint{border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;padding:14px;color:#344054;line-height:1.6}.warn{border-color:#fed7aa;background:#fff7ed}.state{display:inline-flex;align-items:center;border-radius:999px;background:#eef5ff;color:#1769c2;padding:6px 10px;font-weight:900}.actions{margin-top:16px;display:flex;gap:8px;flex-wrap:wrap}.secret-state{font-size:12px;color:#176b2c;font-weight:900}@media(max-width:760px){.grid,.checks{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php echo ieum_admin_header('map_settings'); ?>
<?php echo ieum_admin_subnav('map_settings'); ?>
<main class="wrap">
    <h1>지도 API 설정</h1>
    <div class="meta">본사 공통 설정 · 현재 방식: <span class="state"><?php echo get_text(ieum_map_mode_label($settings)); ?></span></div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="hint">
        이 설정은 <strong>본사 관리자만</strong> 수정합니다. 도장 관리자는 API 키를 보지 않고, 차량 관리 화면에서 주소 검색과 지도 선택 기능만 사용합니다.
        <br>Client Secret은 저장 후 화면에 다시 표시하지 않으며, 서버에서 주소 변환과 경로 계산을 호출할 때만 사용합니다.
    </section>
    <section class="hint warn">
        <strong>네이버 콘솔 확인 순서</strong>
        <br>1. Application에서 Dynamic Map, Geocoding, Directions 5를 선택합니다.
        <br>2. Web 서비스 URL에 실제 접속 주소를 등록합니다. 로컬 테스트는 <code>http://localhost</code>, 태블릿 테스트는 <code>http://192.168.0.81</code>처럼 접속 주소를 함께 넣습니다.
        <br>3. Subscription 또는 이용 신청 상태가 활성화되어야 주소 검색이 동작합니다. 미활성 상태에서는 차량 화면이 “네이버 지도에서 열기”로 대체됩니다.
    </section>

    <form method="post" class="panel">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="checks">
            <label class="check"><input type="checkbox" name="use_dynamic_map" value="1" <?php echo !empty($settings['use_dynamic_map']) ? 'checked' : ''; ?>> <span>Dynamic Map<small>차량 정류장과 노선을 지도에 표시합니다.</small></span></label>
            <label class="check"><input type="checkbox" name="use_geocoding" value="1" <?php echo !empty($settings['use_geocoding']) ? 'checked' : ''; ?>> <span>Geocoding<small>주소를 위도/경도 좌표로 바꿉니다.</small></span></label>
            <label class="check"><input type="checkbox" name="use_directions" value="1" <?php echo !empty($settings['use_directions']) ? 'checked' : ''; ?>> <span>Directions 5<small>차량 동선의 거리와 예상 시간을 계산합니다.</small></span></label>
        </div>
        <div class="grid" style="margin-top:16px">
            <div class="field">
                <label for="naver_client_id">Naver Maps Client ID</label>
                <input type="text" name="naver_client_id" id="naver_client_id" value="<?php echo get_text($settings['naver_client_id']); ?>" maxlength="120" placeholder="Application 인증 정보의 Client ID">
            </div>
            <div class="field">
                <label for="naver_client_secret">Naver Maps Client Secret <?php if ($has_secret) { ?><span class="secret-state">저장됨</span><?php } ?></label>
                <input type="password" name="naver_client_secret" id="naver_client_secret" value="" maxlength="160" placeholder="<?php echo $has_secret ? '변경할 때만 새 Secret 입력' : 'Application 인증 정보의 Client Secret'; ?>">
            </div>
            <div class="field" style="grid-column:1/-1">
                <label for="naver_web_service_url">등록한 Web 서비스 URL</label>
                <input type="text" name="naver_web_service_url" id="naver_web_service_url" value="<?php echo get_text($settings['naver_web_service_url']); ?>" maxlength="255" placeholder="예: http://localhost, http://192.168.0.81, https://wooacha.com">
            </div>
            <div class="field" style="grid-column:1/-1">
                <label for="memo">운영 메모</label>
                <textarea name="memo" id="memo" maxlength="255" placeholder="등록 도메인, API 신청 범위, 계약/과금 확인 내용 등을 적어둡니다."><?php echo get_text($settings['memo']); ?></textarea>
            </div>
        </div>
        <div class="hint warn" style="margin-top:16px">
            운영 서버에 적용할 때는 네이버 클라우드 콘솔의 Web 서비스 URL에 실제 도메인을 반드시 추가해야 합니다.
        </div>
        <div class="actions">
            <button type="submit" class="btn primary">설정 저장</button>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/vehicles.php">차량 관리로 이동</a>
            <a class="btn" href="https://www.ncloud.com/product/applicationService/maps#detail" target="_blank" rel="noopener">Naver Maps 확인</a>
        </div>
    </form>
</main>
</body>
</html>
