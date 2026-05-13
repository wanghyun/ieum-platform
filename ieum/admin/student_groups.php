<?php
$sub_menu = '950160';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';

$g5['title'] = '아이이음 부별 학생 보기';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];

$program_options = ieum_program_options($academy_id, true);
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$grade_group = isset($_GET['grade_group']) ? preg_replace('/[^0-9A-Za-z_]/', '', trim($_GET['grade_group'])) : '';

function ieum_group_grade_options()
{
    return array(
        '' => '전체 학년',
        'kindergarten' => '유치부',
        'elementary_1' => '초등 1학년',
        'elementary_2' => '초등 2학년',
        'elementary_3' => '초등 3학년',
        'elementary_4' => '초등 4학년',
        'elementary_5' => '초등 5학년',
        'elementary_6' => '초등 6학년',
        'middle_1' => '중등 1학년',
        'middle_2' => '중등 2학년',
        'middle_3' => '중등 3학년',
        'high_1' => '고등 1학년',
        'high_2' => '고등 2학년',
        'high_3' => '고등 3학년',
    );
}

function ieum_group_grade_label($value)
{
    $options = ieum_group_grade_options();
    return isset($options[$value]) ? $options[$value] : $value;
}

$class_times = sql_query("
    select *
      from " . IEUM_CLASS_TIME_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by sort_order asc, start_time asc
", false);

$where = " where s.academy_id = '{$academy_id}' and s.is_active = 1 ";
if ($program_code !== '') {
    $program_sql = sql_escape_string($program_code);
    $where .= " and s.program_code = '{$program_sql}' ";
}
if ($class_time_id) {
    $where .= " and s.class_time_id = '{$class_time_id}' ";
}
if ($grade_group !== '') {
    $grade_sql = sql_escape_string($grade_group);
    $where .= " and s.grade_group = '{$grade_sql}' ";
}

$students = sql_query("
    select s.*, c.class_name, c.start_time
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id
      {$where}
  order by c.sort_order asc, c.start_time asc, s.grade_group asc, s.student_name asc
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
.wrap{max-width:1180px;margin:28px auto;padding:0 20px}.bar{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:16px}
h1{margin:0;font-size:26px}.meta{color:#667085}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}
.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center}select{height:38px;border:1px solid #cfd6df;border-radius:6px;padding:0 10px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}.student{border:1px solid #d8dee9;border-radius:8px;padding:14px;background:#fff}.name{font-size:18px;font-weight:900}.sub{color:#667085;margin-top:6px;font-size:13px}
.wrap.is-loading{opacity:.55;pointer-events:none}.filters select{min-width:140px}
</style>
</head>
<body>
<?php echo ieum_admin_header('groups'); ?>
<?php echo ieum_admin_subnav('groups'); ?>
<main class="wrap">
    <div class="bar">
        <div>
            <h1>부별 학생 보기</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?></div>
        </div>
        <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/students.php">학생 관리</a>
    </div>

    <section class="panel">
        <form method="get" class="filters">
            <select name="program_code">
                <option value="">전체 프로그램</option>
                <?php foreach ($program_options as $program) { ?>
                <option value="<?php echo get_text($program['program_code']); ?>" <?php echo get_selected($program_code, $program['program_code']); ?>><?php echo get_text($program['program_name']); ?></option>
                <?php } ?>
            </select>
            <select name="class_time_id">
                <option value="0">전체 부</option>
                <?php while ($class = sql_fetch_array($class_times)) { ?>
                <option value="<?php echo (int) $class['class_time_id']; ?>" <?php echo get_selected($class_time_id, (int) $class['class_time_id']); ?>>
                    <?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?>
                </option>
                <?php } ?>
            </select>
            <select name="grade_group">
                <?php foreach (ieum_group_grade_options() as $value => $label) { ?>
                <option value="<?php echo get_text($value); ?>" <?php echo get_selected($grade_group, $value); ?>><?php echo get_text($label); ?></option>
                <?php } ?>
            </select>
            <button class="btn primary" type="submit">보기</button>
        </form>
    </section>

    <section class="cards">
        <?php $i = 0; while ($row = sql_fetch_array($students)) { $i++; ?>
        <article class="student">
            <div class="name"><?php echo get_text($row['student_name']); ?></div>
            <div class="sub"><?php echo get_text($row['student_code']); ?></div>
            <div class="sub"><?php echo get_text(ieum_program_label($academy_id, isset($row['program_code']) ? $row['program_code'] : '')); ?></div>
            <div class="sub"><?php echo get_text(ieum_group_grade_label($row['grade_group'])); ?></div>
            <div class="sub"><?php echo get_text($row['class_name'] ? $row['class_name'] . ' ' . $row['start_time'] : '부 미지정'); ?></div>
        </article>
        <?php } ?>
        <?php if ($i === 0) { ?><article class="student">조건에 맞는 학생이 없습니다.</article><?php } ?>
    </section>
</main>
<script>
(function () {
    const main = document.querySelector('main.wrap');
    if (!main) return;

    const loadView = async (url, push) => {
        main.classList.add('is-loading');
        try {
            const response = await fetch(url, {
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin'
            });
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
        const select = event.target.closest('form.filters select');
        if (!select) return;
        const form = select.form;
        if (!form) return;
        loadView(buildFormUrl(form), true);
    });

    window.addEventListener('popstate', () => loadView(window.location.href, false));
})();
</script>
</body>
</html>
