<?php
$sub_menu = '950181';
require_once './_common.php';

$g5['title'] = '아이이음 원생 리포트';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

function ieum_growth_source_label($source)
{
    $labels = array('' => '미입력', 'homepage' => '홈페이지', 'referral' => '지인 소개', 'sibling' => '형제/자매', 'sign_walkin' => '간판/지나가다', 'naver_search' => '네이버 검색', 'naver_place' => '네이버 플레이스', 'blog_cafe' => '블로그/카페', 'instagram' => '인스타그램', 'youtube' => '유튜브', 'school_promo' => '학교/유치원 홍보', 'flyer' => '전단지', 'event_trial' => '행사/체험수업', 'etc' => '기타');
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

$annual_new = 0;
$annual_returned = 0;
$annual_paused_in = 0;
$annual_withdrawn = 0;
$annual_net = 0;
$current_active = 0;
$best_month = '';
$best_new = -1;
foreach ($months as $month_row) {
    $annual_new += (int) $month_row['new'];
    $annual_returned += (int) $month_row['returned'];
    $annual_paused_in += (int) $month_row['paused_in'];
    $annual_withdrawn += (int) $month_row['withdrawn'];
    $annual_net += (int) $month_row['new'] + (int) $month_row['returned'] - (int) $month_row['paused_in'] - (int) $month_row['withdrawn'];
    $current_active = (int) $month_row['active'];
    if ((int) $month_row['new'] > $best_new) {
        $best_new = (int) $month_row['new'];
        $best_month = $month_row['month'];
    }
}
$retention_signal = $annual_paused_in + $annual_withdrawn;
$best_month_label = $best_month ? get_text(substr($best_month, 5, 2)) . '월' : '-';

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
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap}.hero h1{margin:0;font-size:34px}.meta{color:#667085;margin-top:6px;line-height:1.45}.filters{display:flex;gap:8px}select,.btn{height:38px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;padding:0 12px;font-weight:900;text-decoration:none}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.summary-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-top:18px}.summary-card{display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:center;background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.summary-icon{width:44px;height:44px;border-radius:14px;background:#eef5ff;display:grid;place-items:center;font-size:25px}.summary-card span{display:block;color:#667085;font-size:13px;font-weight:900}.summary-card strong{display:block;margin-top:3px;font-size:29px}.insight-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:14px}.insight-card{background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.insight-card span{display:block;color:#667085;font-size:13px;font-weight:900}.insight-card strong{display:block;margin-top:7px;font-size:24px}.insight-card p{margin:8px 0 0;color:#667085;line-height:1.5;font-size:13px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-top:18px}.panel h2{margin:0 0 8px}.section-desc{margin:0 0 14px;color:#667085;font-size:13px;line-height:1.5}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center;font-size:14px}th{background:#72829d;color:#fff}.bars{display:grid;gap:10px}.bar-row{display:grid;grid-template-columns:140px 1fr 90px;gap:10px;align-items:center}.track{height:14px;background:#eef2f7;border-radius:999px;overflow:hidden}.fill{height:100%;background:#1769c2}.good{color:#176b2c}.warn{color:#9a5b00}.danger{color:#a4262c}@media(max-width:1100px){.summary-grid,.insight-grid{grid-template-columns:repeat(2,1fr)}.ieum-user{margin-left:0}table{display:block;overflow-x:auto;white-space:nowrap}}@media(max-width:620px){.summary-grid,.insight-grid{grid-template-columns:1fr}.hero h1{font-size:28px}.bar-row{grid-template-columns:1fr}}
</style>
<style>
.detail-panel{padding:0;overflow:hidden}
.detail-panel summary{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:18px;cursor:pointer;list-style:none}
.detail-panel summary::-webkit-details-marker{display:none}
.detail-panel summary h2{margin:0}
.detail-panel summary .section-desc{margin:4px 0 0}
.detail-panel summary:after{content:"펼치기";border-radius:999px;background:#eef5ff;color:#1769c2;padding:7px 10px;font-size:12px;font-weight:1000;white-space:nowrap}
.detail-panel[open] summary{border-bottom:1px solid #e4eaf2}
.detail-panel[open] summary:after{content:"접기";background:#f2f4f7;color:#475467}
.detail-panel-body{padding:16px 18px 18px}
</style>
<style>.wrap.is-loading{opacity:.55;pointer-events:none}</style>
<style>
/* 2026-05-30 easy-mode tune: growth report should answer "is the academy growing?" first. */
body{background:#eef2f7;color:#0f172a}
.wrap{max-width:1900px;margin:0 auto;padding:26px 28px 48px}
.hero{align-items:center;margin-bottom:16px}
.hero h1{font-size:30px;letter-spacing:0}
.meta{font-size:14px;color:#667085}
.filters select,.filters .btn{height:40px;border-radius:10px}
.summary-grid{grid-template-columns:1.2fr repeat(4,1fr);gap:10px;margin-top:14px}
.summary-card,.insight-card,.panel{box-shadow:none;border:1px solid #dfe5ee;border-radius:14px;background:#fff}
.summary-card{padding:15px;grid-template-columns:auto 1fr}
.summary-icon{width:42px;height:42px;border-radius:14px;background:#f1f5f9;font-size:22px}
.summary-card span{font-size:12px;color:#667085}
.summary-card strong{font-size:30px;line-height:1.15}
.summary-card strong.good,.summary-card strong.warn,.summary-card strong.danger{font-weight:1000}
.summary-card:first-child{border-color:#cfe0ff;background:#fbfdff}
.summary-card:first-child .summary-icon{background:#eef5ff}
.insight-grid{gap:10px;margin-top:12px}
.insight-card{padding:17px}
.insight-card span{font-size:12px;color:#667085}
.insight-card strong{font-size:22px;margin-top:5px}
.insight-card p{font-size:13px;color:#667085;line-height:1.55}
.panel{padding:18px;margin-top:16px}
.panel h2{font-size:24px;margin-bottom:6px}
.section-desc{font-size:13px;color:#667085}
th{background:#74839a}
td,th{padding:11px 10px}
.bars{gap:11px}
.bar-row{grid-template-columns:150px 1fr 80px}
.track{height:10px;background:#eef2f7}
.fill{background:#1947ba}
.detail-panel{overflow:hidden}
.detail-panel summary{background:#fff}
.detail-panel summary:after{background:#f1f5f9;color:#334155}
.detail-panel[open] summary{border-bottom:1px solid #e4eaf2}
@media(max-width:1200px){.summary-grid{grid-template-columns:repeat(3,1fr)}.insight-grid{grid-template-columns:1fr}}
@media(max-width:760px){.summary-grid{grid-template-columns:1fr 1fr}.wrap{padding:20px 16px 40px}.bar-row{grid-template-columns:1fr}}
@media(max-width:520px){.summary-grid{grid-template-columns:1fr}}
</style>
<style>
body.ieum-side-layout.ieum-dashboard-page.growth-report-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.growth-report-page-tune .ieum-side{width:260px!important;background:#fff;border-right:1px solid #e5e7eb}
.growth-report-page-tune .side-brand{height:144px;padding:0 28px;align-items:center;font-size:30px;font-weight:900;letter-spacing:0}
.growth-report-page-tune .side-brand-mark,
.growth-report-page-tune .side-profile,
.growth-report-page-tune .side-search,
.growth-report-page-tune .ieum-right-rail{display:none!important}
.growth-report-page-tune .side-nav{padding:0 14px 22px}
.growth-report-page-tune .side-nav a{border-radius:8px;color:#0f172a}
.growth-report-page-tune .side-nav a.active{background:#f1f5f9;color:#0f172a}
.growth-report-page-tune .ieum-shell-top{
    left:260px;
    height:64px;
    background:#fff;
    border-bottom:1px solid #eef2f7;
    color:#0f172a;
    box-shadow:none;
}
.growth-report-page-tune .ieum-shell-link,
.growth-report-page-tune .dashboard-shell-support-link{color:#0f172a;text-decoration:none;font-weight:800}
.growth-report-page-tune .ieum-shell-meta{color:#0f172a}
.growth-report-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.growth-report-page-tune .dashboard-shell-divider,
.growth-report-page-tune .dashboard-shell-help-dot{color:#94a3b8}
.growth-report-page-tune .wrap{
    max-width:none;
    width:auto;
    margin:0 0 0 260px;
    padding:96px 40px 42px;
}
.growth-report-page-tune .hero{
    align-items:flex-end;
    margin-bottom:16px;
}
.growth-report-page-tune .hero h1{font-size:30px;line-height:1.2}
.growth-report-page-tune .summary-card,
.growth-report-page-tune .insight-card,
.growth-report-page-tune .panel{border-radius:8px;box-shadow:none}
.growth-report-page-tune .summary-icon{
    width:38px;
    height:38px;
    border-radius:8px;
    background:#eef5ff;
    color:#2563eb;
    font-size:13px;
    font-weight:900;
}
.growth-report-page-tune th{background:#f8fafc;color:#475569;border-color:#e5e7eb}
.growth-report-page-tune td{border-color:#eef2f7}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.growth-report-page-tune{--ieum-side-width:0px}
    .growth-report-page-tune .ieum-shell-top{left:0}
    .growth-report-page-tune .wrap{margin-left:0;padding:88px 16px 32px}
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page growth-report-page-tune">
<?php echo ieum_admin_header('growth', 'side'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>원생 리포트</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo (int) $year; ?>년 · 신규/휴관/퇴관 흐름을 보는 화면</div>
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

    <section class="summary-grid">
        <article class="summary-card"><div class="summary-icon">재</div><div><span>현재 재원</span><strong><?php echo number_format($current_active); ?>명</strong></div></article>
        <article class="summary-card"><div class="summary-icon">신</div><div><span>올해 신규</span><strong class="good"><?php echo number_format($annual_new); ?>명</strong></div></article>
        <article class="summary-card"><div class="summary-icon">휴</div><div><span>올해 휴관</span><strong class="warn"><?php echo number_format($annual_paused_in); ?>명</strong></div></article>
        <article class="summary-card"><div class="summary-icon">퇴</div><div><span>올해 퇴관</span><strong class="danger"><?php echo number_format($annual_withdrawn); ?>명</strong></div></article>
        <article class="summary-card"><div class="summary-icon">증</div><div><span>올해 순증감</span><strong class="<?php echo $annual_net >= 0 ? 'good' : 'danger'; ?>"><?php echo ($annual_net > 0 ? '+' : '') . number_format($annual_net); ?>명</strong></div></article>
    </section>

    <section class="insight-grid" aria-label="원생 흐름 해석">
        <article class="insight-card">
            <span>가장 입관이 많았던 달</span>
            <strong><?php echo $best_month_label; ?> · <?php echo number_format(max(0, $best_new)); ?>명</strong>
            <p>해당 월의 홍보, 체험수업, 소개 흐름을 다시 보면 반복 가능한 입관 포인트를 찾을 수 있습니다.</p>
        </article>
        <article class="insight-card">
            <span>이탈/휴관 신호</span>
            <strong class="<?php echo $retention_signal ? 'warn' : 'good'; ?>"><?php echo number_format($retention_signal); ?>명</strong>
            <p>휴관과 퇴관은 별도 숫자보다 상담 타이밍으로 보는 것이 좋습니다. 원인 메모가 쌓이면 다음 달 운영이 쉬워집니다.</p>
        </article>
        <article class="insight-card">
            <span>다음 확인</span>
            <strong class="<?php echo $annual_net >= 0 ? 'good' : 'danger'; ?>"><?php echo $annual_net >= 0 ? '성장 흐름' : '점검 필요'; ?></strong>
            <p>순증감, 입관 경로, 학년 분포를 함께 보고 다음 달 모집/상담 우선순위를 정합니다.</p>
        </article>
    </section>

    <section class="panel">
        <h2>월별 원생 흐름</h2>
        <p class="section-desc">어느 달에 입관이 몰리고, 휴관/퇴관 신호가 늘었는지 확인합니다. 가장 많이 등록된 달은 <?php echo $best_month_label; ?>입니다.</p>
        <div class="table-wrap">
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
        </div>
    </section>

    <details class="panel detail-panel">
        <summary>
            <div>
                <h2>입관 경로 TOP</h2>
                <p class="section-desc">신규 원생이 어디서 들어왔는지 필요할 때 펼쳐 보고 다음 홍보 판단에 씁니다.</p>
            </div>
        </summary>
        <div class="detail-panel-body">
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
        </div>
    </details>
</main>
<script>
(function(){
    var rootSelector = '.growth-report-page-tune.ieum-dashboard-page';
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
