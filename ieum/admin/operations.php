<?php
$sub_menu = '950180';
require_once './_common.php';
require_once IEUM_PATH . '/lib/tuition.php';
require_once IEUM_PATH . '/lib/program.php';

$g5['title'] = '아이이음 도장 운영지표';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$month = isset($_GET['month']) ? preg_replace('/[^0-9\-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}\-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$start_date = $month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));
$prev_month = date('Y-m', strtotime($start_date . ' -1 month'));
$next_month = date('Y-m', strtotime($start_date . ' +1 month'));

function ieum_ops_count_status($academy_id, $status)
{
    $academy_id = (int) $academy_id;
    $status_sql = sql_escape_string($status);
    $row = sql_fetch("
        select count(*) as cnt
          from " . IEUM_STUDENT_TABLE . "
         where academy_id = '{$academy_id}'
           and student_status = '{$status_sql}'
           and is_active = 1
    ", false);
    return isset($row['cnt']) ? (int) $row['cnt'] : 0;
}

function ieum_ops_status_label($status)
{
    $labels = array('enrolled' => '재원', 'trial' => '체험', 'paused' => '휴관', 'returned' => '복귀', 'withdrawn' => '퇴관', 'waiting' => '대기');
    return isset($labels[$status]) ? $labels[$status] : $status;
}

function ieum_ops_grade_label($value)
{
    $labels = array('' => '미지정', 'kindergarten' => '유치부', 'elementary_1' => '초등 1학년', 'elementary_2' => '초등 2학년', 'elementary_3' => '초등 3학년', 'elementary_4' => '초등 4학년', 'elementary_5' => '초등 5학년', 'elementary_6' => '초등 6학년', 'middle_1' => '중등 1학년', 'middle_2' => '중등 2학년', 'middle_3' => '중등 3학년', 'high_1' => '고등 1학년', 'high_2' => '고등 2학년', 'high_3' => '고등 3학년', 'adult' => '성인부', 'jump_rope' => '줄넘기부');
    return isset($labels[$value]) ? $labels[$value] : $value;
}

function ieum_ops_source_label($source)
{
    $labels = array('' => '미입력', 'referral' => '지인 소개', 'sibling' => '형제/자매', 'sign_walkin' => '간판/지나가다', 'naver_search' => '네이버 검색', 'naver_place' => '네이버 플레이스', 'blog_cafe' => '블로그/카페', 'instagram' => '인스타그램', 'youtube' => '유튜브', 'school_promo' => '학교/유치원 홍보', 'flyer' => '전단지', 'event_trial' => '행사/체험수업', 'etc' => '기타');
    return isset($labels[$source]) ? $labels[$source] : $source;
}

function ieum_ops_tuition_status_label($status)
{
    $labels = array('paid' => '완납', 'partial' => '미납', 'unpaid' => '미납');
    return isset($labels[$status]) ? $labels[$status] : ($status ?: '-');
}

ieum_tuition_ensure_month($academy_id, $month);

$active_count = sql_fetch("select count(*) as cnt from " . IEUM_STUDENT_TABLE . " where academy_id = '{$academy_id}' and is_active = 1 and student_status in ('enrolled','returned')", false);
$new_count = sql_fetch("select count(*) as cnt from " . IEUM_STUDENT_TABLE . " where academy_id = '{$academy_id}' and admission_date between '{$start_date}' and '{$end_date}'", false);
$paused_in_count = sql_fetch("select count(distinct student_id) as cnt from " . IEUM_STUDENT_STATUS_LOG_TABLE . " where academy_id = '{$academy_id}' and after_status = 'paused' and changed_date between '{$start_date}' and '{$end_date}'", false);
$paused_out_count = sql_fetch("select count(distinct student_id) as cnt from " . IEUM_STUDENT_STATUS_LOG_TABLE . " where academy_id = '{$academy_id}' and before_status = 'paused' and after_status <> 'paused' and changed_date between '{$start_date}' and '{$end_date}'", false);
$paused_delta = (int) $paused_in_count['cnt'] - (int) $paused_out_count['cnt'];
$returned_count = sql_fetch("select count(distinct student_id) as cnt from " . IEUM_STUDENT_STATUS_LOG_TABLE . " where academy_id = '{$academy_id}' and after_status in ('returned','enrolled') and before_status = 'paused' and changed_date between '{$start_date}' and '{$end_date}'", false);
$withdrawn_count = sql_fetch("select count(*) as cnt from " . IEUM_STUDENT_STATUS_LOG_TABLE . " where academy_id = '{$academy_id}' and after_status = 'withdrawn' and changed_date between '{$start_date}' and '{$end_date}'", false);
$net_change = (int) $new_count['cnt'] + (int) $returned_count['cnt'] - (int) $paused_in_count['cnt'] - (int) $withdrawn_count['cnt'];

$status_rows = sql_query("
    select student_status, count(*) as cnt
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
  group by student_status
  order by field(student_status, 'enrolled', 'trial', 'waiting', 'paused', 'returned', 'withdrawn')
", false);

$grade_rows = sql_query("
    select case when program_code = 'jump_rope' then 'jump_rope' else grade_group end as grade_group,
           count(*) as cnt
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  group by case when program_code = 'jump_rope' then 'jump_rope' else grade_group end
  order by case
             when field(grade_group, 'kindergarten', 'elementary_1', 'elementary_2', 'elementary_3', 'elementary_4', 'elementary_5', 'elementary_6', 'middle_1', 'middle_2', 'middle_3', 'high_1', 'high_2', 'high_3', 'adult', 'jump_rope', '') = 0 then 999
             else field(grade_group, 'kindergarten', 'elementary_1', 'elementary_2', 'elementary_3', 'elementary_4', 'elementary_5', 'elementary_6', 'middle_1', 'middle_2', 'middle_3', 'high_1', 'high_2', 'high_3', 'adult', 'jump_rope', '')
           end asc,
           grade_group asc
", false);

$source_rows = sql_query("
    select enrollment_source, count(*) as cnt
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and admission_date between '{$start_date}' and '{$end_date}'
  group by enrollment_source
  order by cnt desc, enrollment_source asc
     limit 8
", false);

$risk_rows = sql_query("
    select s.student_id, s.student_code, s.student_name, s.student_status, s.memo,
           count(a.attendance_id) as attendance_count,
           coalesce(max(tp.due_date), '') as due_date,
           coalesce(max(tp.status), '') as tuition_status,
           coalesce(max(tp.amount_due), 0) as amount_due,
           coalesce(max(tp.amount_paid), 0) as amount_paid
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_ATTENDANCE_TABLE . " a on a.academy_id = s.academy_id and a.student_id = s.student_id and a.attendance_date between date_sub('" . G5_TIME_YMD . "', interval 14 day) and '" . G5_TIME_YMD . "'
 left join " . IEUM_TUITION_PAYMENT_TABLE . " tp on tp.academy_id = s.academy_id and tp.student_id = s.student_id and tp.billing_month = '" . sql_escape_string($month) . "' and tp.status in ('unpaid','partial')
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
  group by s.student_id
    having attendance_count <= 1 or tuition_status <> ''
  order by tuition_status desc, attendance_count asc, s.student_name asc
     limit 12
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1220px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;gap:14px;align-items:flex-end;flex-wrap:wrap}.meta{color:#667085;margin-top:6px}.filters{display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:800}.primary{background:#1769c2;border-color:#1769c2;color:#fff}input{height:38px;border:1px solid #cfd6df;border-radius:8px;padding:0 10px}.grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:18px 0}.card{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.label{font-size:13px;color:#667085}.num{font-size:30px;font-weight:900;margin-top:4px}.main{display:grid;grid-template-columns:1fr 1fr;gap:18px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06)}h2{margin:0 0 12px;font-size:20px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:9px;text-align:center;font-size:14px}th{background:#72829d;color:#fff}.left{text-align:left}.good{color:#176b2c}.warn{color:#9a5b00}.danger{color:#a4262c}@media(max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}.main{grid-template-columns:1fr}.ieum-user{margin-left:0}table{display:block;overflow-x:auto;white-space:nowrap}}@media(max-width:520px){.grid{grid-template-columns:1fr}}
</style>
<style>.wrap.is-loading{opacity:.55;pointer-events:none}</style>
</head>
<body>
<?php echo ieum_admin_header('operations'); ?>
<?php echo ieum_admin_subnav('operations'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>도장 운영지표</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($month); ?></div>
        </div>
        <form method="get" class="filters">
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/operations.php?month=<?php echo get_text($prev_month); ?>">이전달</a>
            <input type="month" name="month" value="<?php echo get_text($month); ?>">
            <button class="btn primary" type="submit">조회</button>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/operations.php?month=<?php echo get_text($next_month); ?>">다음달</a>
        </form>
    </section>

    <section class="grid">
        <article class="card"><div class="label">현재 재원 학생</div><div class="num"><?php echo number_format((int) $active_count['cnt']); ?></div></article>
        <article class="card"><div class="label">신규 등록</div><div class="num good"><?php echo number_format((int) $new_count['cnt']); ?></div></article>
        <article class="card"><div class="label">휴관 변동</div><div class="num warn"><?php echo ($paused_delta > 0 ? '+' : '') . number_format($paused_delta); ?></div></article>
        <article class="card"><div class="label">퇴관</div><div class="num danger"><?php echo number_format((int) $withdrawn_count['cnt']); ?></div></article>
        <article class="card"><div class="label">순증감</div><div class="num <?php echo $net_change >= 0 ? 'good' : 'danger'; ?>"><?php echo ($net_change > 0 ? '+' : '') . number_format($net_change); ?></div></article>
    </section>

    <section class="main">
        <article class="panel">
            <h2>원생 상태</h2>
            <table><thead><tr><th>상태</th><th>인원</th></tr></thead><tbody>
            <?php while ($row = sql_fetch_array($status_rows)) { ?>
            <tr><td><?php echo get_text(ieum_ops_status_label($row['student_status'])); ?></td><td><?php echo number_format((int) $row['cnt']); ?></td></tr>
            <?php } ?>
            </tbody></table>
        </article>
        <article class="panel">
            <h2>이번 달 입관 경로</h2>
            <table><thead><tr><th>경로</th><th>신규</th></tr></thead><tbody>
            <?php $si=0; while ($row = sql_fetch_array($source_rows)) { $si++; ?>
            <tr><td><?php echo get_text(ieum_ops_source_label($row['enrollment_source'])); ?></td><td><?php echo number_format((int) $row['cnt']); ?></td></tr>
            <?php } if ($si===0) { ?><tr><td colspan="2">이번 달 신규 등록 경로가 없습니다.</td></tr><?php } ?>
            </tbody></table>
        </article>
        <article class="panel">
            <h2>학년/부 분포</h2>
            <table><thead><tr><th>학년/부</th><th>인원</th></tr></thead><tbody>
            <?php while ($row = sql_fetch_array($grade_rows)) { ?>
            <tr><td><?php echo get_text(ieum_ops_grade_label($row['grade_group'])); ?></td><td><?php echo number_format((int) $row['cnt']); ?></td></tr>
            <?php } ?>
            </tbody></table>
        </article>
        <article class="panel">
            <h2>상담 필요 신호</h2>
            <table><thead><tr><th>학생</th><th>최근 14일 출석</th><th>수련비</th><th>메모</th></tr></thead><tbody>
            <?php $ri=0; while ($row = sql_fetch_array($risk_rows)) { $ri++; ?>
            <tr>
                <td><?php echo get_text($row['student_name'] . ' (' . $row['student_code'] . ')'); ?></td>
                <td><?php echo number_format((int) $row['attendance_count']); ?></td>
                <td><?php echo $row['tuition_status'] ? get_text(ieum_ops_tuition_status_label($row['tuition_status']) . ' ' . number_format(max(0, (int) $row['amount_due'] - (int) $row['amount_paid'])) . '원') : '-'; ?></td>
                <td class="left"><?php echo get_text($row['memo']); ?></td>
            </tr>
            <?php } if ($ri===0) { ?><tr><td colspan="4">현재 상담 필요 신호가 없습니다.</td></tr><?php } ?>
            </tbody></table>
        </article>
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
    main.addEventListener('submit', (event) => {
        const form = event.target.closest('form.filters');
        if (!form || String(form.method || 'get').toLowerCase() !== 'get') return;
        event.preventDefault();
        const url = form.action || window.location.pathname;
        loadView(url + '?' + new URLSearchParams(new FormData(form)).toString(), true);
    });
    main.addEventListener('change', (event) => {
        const control = event.target.closest('form.filters input');
        if (!control) return;
        const form = control.form;
        if (!form) return;
        const url = form.action || window.location.pathname;
        loadView(url + '?' + new URLSearchParams(new FormData(form)).toString(), true);
    });
    main.addEventListener('click', (event) => {
        const link = event.target.closest('form.filters a.btn');
        if (!link || link.target) return;
        event.preventDefault();
        loadView(link.href, true);
    });
    window.addEventListener('popstate', () => loadView(window.location.href, false));
})();
</script>
</body>
</html>
