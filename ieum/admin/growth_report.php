<?php
$sub_menu = '950181';
require_once './_common.php';

$g5['title'] = '아이이음 도장 성장 리포트';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

function ieum_growth_source_label($source)
{
    $labels = array('' => '미입력', 'referral' => '지인 소개', 'sibling' => '형제/자매', 'sign_walkin' => '간판/지나가다', 'naver_search' => '네이버 검색', 'naver_place' => '네이버 플레이스', 'blog_cafe' => '블로그/카페', 'instagram' => '인스타그램', 'youtube' => '유튜브', 'school_promo' => '학교/유치원 홍보', 'flyer' => '전단지', 'event_trial' => '행사/체험수업', 'etc' => '기타');
    return isset($labels[$source]) ? $labels[$source] : $source;
}

$months = array();
for ($m = 1; $m <= 12; $m++) {
    $month = sprintf('%04d-%02d', $year, $m);
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));
    $new = sql_fetch("select count(*) as cnt from " . IEUM_STUDENT_TABLE . " where academy_id='{$academy_id}' and admission_date between '{$start}' and '{$end}'", false);
    $paused_in = sql_fetch("select count(distinct student_id) as cnt from " . IEUM_STUDENT_STATUS_LOG_TABLE . " where academy_id='{$academy_id}' and after_status='paused' and changed_date between '{$start}' and '{$end}'", false);
    $paused_out = sql_fetch("select count(distinct student_id) as cnt from " . IEUM_STUDENT_STATUS_LOG_TABLE . " where academy_id='{$academy_id}' and before_status='paused' and after_status <> 'paused' and changed_date between '{$start}' and '{$end}'", false);
    $withdrawn = sql_fetch("select count(*) as cnt from " . IEUM_STUDENT_STATUS_LOG_TABLE . " where academy_id='{$academy_id}' and after_status='withdrawn' and changed_date between '{$start}' and '{$end}'", false);
    $returned = sql_fetch("select count(distinct student_id) as cnt from " . IEUM_STUDENT_STATUS_LOG_TABLE . " where academy_id='{$academy_id}' and after_status in ('returned','enrolled') and before_status='paused' and changed_date between '{$start}' and '{$end}'", false);
    $active = sql_fetch("select count(*) as cnt from " . IEUM_STUDENT_TABLE . " where academy_id='{$academy_id}' and is_active=1 and student_status in ('enrolled','returned') and (admission_date is null or admission_date <= '{$end}')", false);
    $months[] = array(
        'month' => $month,
        'new' => (int) $new['cnt'],
        'paused' => (int) $paused_in['cnt'] - (int) $paused_out['cnt'],
        'paused_in' => (int) $paused_in['cnt'],
        'returned' => (int) $returned['cnt'],
        'withdrawn' => (int) $withdrawn['cnt'],
        'active' => (int) $active['cnt'],
    );
}

$source_rows = sql_query("
    select enrollment_source, count(*) as cnt
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and admission_date between '{$year}-01-01' and '{$year}-12-31'
  group by enrollment_source
  order by cnt desc
     limit 10
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1220px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap}.meta{color:#667085;margin-top:6px}.filters{display:flex;gap:8px}select,.btn{height:38px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;padding:0 12px;font-weight:800;text-decoration:none}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-top:18px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:9px;text-align:center;font-size:14px}th{background:#72829d;color:#fff}.bars{display:grid;gap:10px}.bar-row{display:grid;grid-template-columns:90px 1fr 90px;gap:10px;align-items:center}.track{height:12px;background:#eef2f7;border-radius:999px;overflow:hidden}.fill{height:100%;background:#1769c2}.good{color:#176b2c}.danger{color:#a4262c}@media(max-width:900px){.ieum-user{margin-left:0}table{display:block;overflow-x:auto;white-space:nowrap}}
</style>
<style>.wrap.is-loading{opacity:.55;pointer-events:none}</style>
</head>
<body>
<?php echo ieum_admin_header('growth'); ?>
<?php echo ieum_admin_subnav('growth'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>도장 성장 리포트</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo (int) $year; ?>년</div>
        </div>
        <form method="get" class="filters">
            <select name="year">
                <?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 5; $y--) { ?>
                <option value="<?php echo $y; ?>" <?php echo get_selected($year, $y); ?>><?php echo $y; ?>년</option>
                <?php } ?>
            </select>
            <button class="btn primary" type="submit">조회</button>
        </form>
    </section>

    <section class="panel">
        <h2>월별 원생 흐름</h2>
        <table>
            <thead><tr><th>월</th><th>재원</th><th>신규</th><th>복귀</th><th>휴관</th><th>퇴관</th><th>순증감</th></tr></thead>
            <tbody>
            <?php foreach ($months as $row) { $net = $row['new'] + $row['returned'] - $row['paused_in'] - $row['withdrawn']; ?>
            <tr>
                <td><?php echo get_text(substr($row['month'], 5, 2)); ?>월</td>
                <td><?php echo number_format($row['active']); ?></td>
                <td class="good"><?php echo number_format($row['new']); ?></td>
                <td class="good"><?php echo number_format($row['returned']); ?></td>
                <td class="<?php echo $row['paused'] > 0 ? 'danger' : ($row['paused'] < 0 ? 'good' : ''); ?>"><?php echo number_format($row['paused']); ?></td>
                <td class="danger"><?php echo number_format($row['withdrawn']); ?></td>
                <td class="<?php echo $net >= 0 ? 'good' : 'danger'; ?>"><?php echo ($net > 0 ? '+' : '') . number_format($net); ?></td>
            </tr>
            <?php } ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <h2>입관 경로 TOP</h2>
        <div class="bars">
        <?php $max = 1; $sources = array(); while ($row = sql_fetch_array($source_rows)) { $sources[] = $row; $max = max($max, (int) $row['cnt']); } ?>
        <?php foreach ($sources as $row) { $rate = round(((int) $row['cnt'] / $max) * 100); ?>
            <div class="bar-row">
                <strong><?php echo get_text(ieum_growth_source_label($row['enrollment_source'])); ?></strong>
                <div class="track"><div class="fill" style="width:<?php echo (int) $rate; ?>%"></div></div>
                <span><?php echo number_format((int) $row['cnt']); ?>명</span>
            </div>
        <?php } ?>
        <?php if (!$sources) { ?><p>올해 신규 입관 경로 데이터가 없습니다.</p><?php } ?>
        </div>
    </section>
</main>
<script>
(function () {
    const main = document.querySelector('main.wrap');
    if (!main) return;
    const loadView = async (url, push) => {
        main.classList.add('is-loading');
        try {
            const response = await fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'});
            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const next = doc.querySelector('main.wrap');
            if (!next) {
                window.location.href = url;
                return;
            }
            main.innerHTML = next.innerHTML;
            if (push) history.pushState({ieumAjax: true}, '', url);
        } catch (error) {
            window.location.href = url;
        } finally {
            main.classList.remove('is-loading');
        }
    };
    const buildFormUrl = (form) => {
        const url = new URL(form.action || window.location.href, window.location.href);
        url.search = new URLSearchParams(new FormData(form)).toString();
        return url.toString();
    };
    main.addEventListener('submit', (event) => {
        const form = event.target.closest('form.filters');
        if (!form || String(form.method || 'get').toLowerCase() !== 'get') return;
        event.preventDefault();
        loadView(buildFormUrl(form), true);
    });
    main.addEventListener('change', (event) => {
        const control = event.target.closest('form.filters select');
        if (!control) return;
        const form = control.form;
        if (!form) return;
        loadView(buildFormUrl(form), true);
    });
    window.addEventListener('popstate', () => loadView(window.location.href, false));
})();
</script>
</body>
</html>
