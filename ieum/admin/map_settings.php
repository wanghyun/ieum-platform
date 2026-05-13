<?php
$sub_menu = '950148';
require_once './_common.php';
require_once IEUM_PATH . '/lib/maps.php';

$g5['title'] = '아이이음 지도 API 설정';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $current = ieum_map_get_settings($academy_id);
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

        sql_query("
            insert into " . IEUM_MAP_SETTING_TABLE . "
                set academy_id = '{$academy_id}',
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

$settings = ieum_map_get_settings($academy_id);
$csrf_token = ieum_new_csrf_token();
$has_secret = !empty($settings['naver_client_secret']);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:980px;margin:28px auto;padding:0 20px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}h1{margin:0 0 8px;font-size:28px}.meta{color:#667085;margin-bottom:16px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field{display:grid;gap:7px}.field label{font-weight:900;color:#344054}input[type=text],input[type=password],textarea{width:100%;border:1px solid #cfd6df;border-radius:8px;padding:11px;font-size:15px}textarea{min-height:86px;resize:vertical}.checks{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.check{display:flex;gap:8px;align-items:flex-start;border:1px solid #d9e2f1;border-radius:8px;background:#fbfcff;padding:12px;font-weight:900}.check small{display:block;color:#667085;font-weight:700;margin-top:4px;line-height:1.45}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:9px 14px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.hint{border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;padding:14px;color:#344054;line-height:1.6}.state{display:inline-flex;align-items:center;border-radius:999px;background:#eef5ff;color:#1769c2;padding:6px 10px;font-weight:900}.actions{margin-top:16px;display:flex;gap:8px;flex-wrap:wrap}@media(max-width:760px){.grid,.checks{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php echo ieum_admin_header('map_settings'); ?>
<?php echo ieum_admin_subnav('map_settings'); ?>
<main class="wrap">
    <h1>지도 API 설정</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 현재 방식: <span class="state"><?php echo get_text(ieum_map_mode_label($settings)); ?></span></div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="hint">
        지금은 API 키가 없어도 차량 관리의 <strong>지도 검색 링크</strong>로 운영할 수 있습니다.
        네이버 클라우드 Maps API를 연결하면 정류장 주소 좌표 변환, 관리자 화면 내 지도 표시, 경로 계산 기능을 단계적으로 켤 수 있습니다.
    </section>

    <form method="post" class="panel">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="checks">
            <label class="check"><input type="checkbox" name="use_dynamic_map" value="1" <?php echo !empty($settings['use_dynamic_map']) ? 'checked' : ''; ?>> <span>지도 표시<small>차량/정류장을 관리 화면 안에서 확인</small></span></label>
            <label class="check"><input type="checkbox" name="use_geocoding" value="1" <?php echo !empty($settings['use_geocoding']) ? 'checked' : ''; ?>> <span>주소 좌표 변환<small>주소 입력 후 위도/경도 자동 저장</small></span></label>
            <label class="check"><input type="checkbox" name="use_directions" value="1" <?php echo !empty($settings['use_directions']) ? 'checked' : ''; ?>> <span>경로 계산<small>정류장 순서와 예상 이동 흐름 검토</small></span></label>
        </div>
        <div class="grid" style="margin-top:16px">
            <div class="field">
                <label for="naver_client_id">네이버 Maps Client ID</label>
                <input type="text" name="naver_client_id" id="naver_client_id" value="<?php echo get_text($settings['naver_client_id']); ?>" maxlength="120" placeholder="API 등록 후 발급받은 Client ID">
            </div>
            <div class="field">
                <label for="naver_client_secret">네이버 Maps Client Secret</label>
                <input type="password" name="naver_client_secret" id="naver_client_secret" value="" maxlength="160" placeholder="<?php echo $has_secret ? '저장됨 - 변경할 때만 입력' : 'API 등록 후 발급받은 Secret'; ?>">
            </div>
            <div class="field" style="grid-column:1/-1">
                <label for="naver_web_service_url">Web 서비스 URL</label>
                <input type="text" name="naver_web_service_url" id="naver_web_service_url" value="<?php echo get_text($settings['naver_web_service_url']); ?>" maxlength="255" placeholder="예: https://ieum.example.com 또는 로컬 테스트 주소">
            </div>
            <div class="field" style="grid-column:1/-1">
                <label for="memo">메모</label>
                <textarea name="memo" id="memo" maxlength="255" placeholder="본사 계약 키 사용 여부, 등록 도메인, 적용 예정 기능 등을 적어둡니다."><?php echo get_text($settings['memo']); ?></textarea>
            </div>
        </div>
        <div class="actions">
            <button type="submit" class="btn primary">저장</button>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/vehicles.php">차량 관리로 이동</a>
            <a class="btn" href="https://www.ncloud.com/product/applicationService/maps#detail" target="_blank" rel="noopener">네이버 Maps 확인</a>
        </div>
    </form>
</main>
</body>
</html>
