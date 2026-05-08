<?php
require_once './_common.php';
require_once IEUM_PATH . '/lib/security.php';
require_once IEUM_PATH . '/lib/academy.php';

if (!$is_member) {
    goto_url(G5_BBS_URL . '/login.php?url=' . urlencode(IEUM_URL . '/kiosk.php'));
}

$current_academy = ieum_require_academy_page();
$token = ieum_new_csrf_token();
$tablet_mode = isset($_GET['tablet']) && $_GET['tablet'] === '1';
$tablet_layout = isset($_GET['layout']) && $_GET['layout'] === 'portrait' ? 'portrait' : 'landscape';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>아이이음 출석 키오스크</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f6f7f9;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
body.tablet{background:#111827;overflow:hidden}
.wrap{min-height:100vh;display:grid;place-items:center;padding:24px}
.panel{width:min(560px,100%);background:#fff;border:1px solid #dde1e7;border-radius:8px;padding:28px;box-shadow:0 10px 30px rgba(15,23,42,.08)}
body.tablet .panel{width:min(760px,calc(100vw - 24px));max-height:calc(100vh - 24px);padding:24px;overflow:hidden}
body.tablet .wrap{padding:16px}
h1{margin:0 0 10px;font-size:28px;line-height:1.2}
body.tablet h1{font-size:42px}
.sub{margin:0 0 22px;color:#5b6472}
.display{width:100%;height:78px;border:2px solid #111827;border-radius:8px;font-size:42px;text-align:center;letter-spacing:2px;margin-bottom:16px}
body.tablet .display{height:104px;font-size:58px}
.keys{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
button{height:68px;border:1px solid #cfd6df;border-radius:8px;background:#fff;font-size:28px;font-weight:700;cursor:pointer}
body.tablet button{height:88px;font-size:36px}
button:active{transform:translateY(1px)}
.submit{grid-column:span 2;background:#1565c0;color:#fff;border-color:#1565c0}
.clear{background:#f1f3f5}
.status{height:210px;margin-top:18px;padding:14px;border-radius:8px;background:#eef2f7;color:#1f2937;font-size:18px;display:flex;align-items:center;justify-content:center;overflow:hidden}
body.tablet .status{height:250px}
.status.ok{background:#e8f7ee;color:#146c2e}
.status.warn{background:#fff4e6;color:#9a5b00}
.status.err{background:#fdecec;color:#a4262c}
.progress-card{display:grid;gap:8px;width:100%;text-align:center}
.student-photo{width:92px;height:92px;border-radius:999px;object-fit:cover;border:4px solid #fff;box-shadow:0 6px 18px rgba(15,23,42,.18);margin:0 auto 2px}
body.tablet .student-photo{width:112px;height:112px}
.progress-card strong{font-size:24px;color:#111827}
.progress-line{font-size:18px;color:#334155}
.progress-bar{height:12px;background:#dbe4ef;border-radius:999px;overflow:hidden}
.progress-fill{height:100%;background:#1769c2;border-radius:999px}
.level-card{display:flex;align-items:center;justify-content:center;gap:10px;background:#fff;border:1px solid #d8dee9;border-radius:14px;padding:9px 11px;text-align:left}
.level-badge{min-width:96px;border-radius:999px;color:#fff;padding:8px 10px;font-weight:900;text-align:center;box-shadow:inset 0 0 0 3px rgba(255,255,255,.18)}
.level-copy{display:grid;gap:2px}.level-copy b{font-size:15px;color:#172033}.level-copy span{font-size:13px;color:#667085}.level-progress{height:9px}
.motivation{font-weight:800;color:#146c2e;line-height:1.35}
.choice-list{display:grid;gap:8px;width:100%}
.status .choice-btn{height:auto;min-height:56px;display:grid;grid-template-columns:auto 1fr;gap:10px;align-items:center;text-align:left;padding:8px 10px;font-size:16px;background:#fff}
.choice-btn img{width:44px;height:44px;border-radius:999px;object-fit:cover}
.choice-thumb{width:44px;height:44px;border-radius:999px;background:#dbe4ef;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:900;color:#536176}
.choice-btn strong{font-size:18px}.choice-btn span{display:block;color:#5b6472;font-size:14px}
.meta{margin-top:12px;color:#697386;font-size:14px}
@media (orientation:landscape) and (max-height:720px){
body.tablet .wrap{padding:8px}
body.tablet .panel{width:calc(100vw - 16px);height:calc(100vh - 16px);padding:14px;display:grid;grid-template-columns:minmax(340px,1fr) minmax(300px,.9fr);grid-template-rows:auto auto 1fr auto;gap:8px 14px}
body.tablet h1{grid-column:1 / 3;font-size:30px;margin:0}
body.tablet .sub{grid-column:1 / 3;margin:0;font-size:14px}
body.tablet .display{grid-column:1;height:58px;font-size:36px;margin:0}
body.tablet .keys{grid-column:1;align-self:start;gap:6px}
body.tablet button{height:48px;font-size:24px}
body.tablet .status{grid-column:2;grid-row:3 / 5;height:100%;margin:0;padding:10px;font-size:15px}
body.tablet .student-photo{width:82px;height:82px}
body.tablet .progress-card strong{font-size:20px}
body.tablet .progress-line{font-size:15px}
body.tablet .motivation{font-size:15px}
body.tablet .meta{grid-column:1;margin:0;font-size:12px}
body.tablet .status .choice-btn{height:auto;min-height:48px;font-size:14px}
}
@media (orientation:portrait) and (max-width:720px){
body.tablet .wrap{padding:8px}
body.tablet .panel{width:calc(100vw - 16px);height:calc(100vh - 16px);padding:14px}
body.tablet h1{font-size:30px}
body.tablet .sub{margin-bottom:10px;font-size:14px}
body.tablet .display{height:64px;font-size:38px;margin-bottom:8px}
body.tablet .keys{gap:6px}
body.tablet button{height:54px;font-size:24px}
body.tablet .status{height:188px;margin-top:10px;font-size:15px;padding:10px}
body.tablet .student-photo{width:74px;height:74px}
body.tablet .progress-card{gap:5px}
body.tablet .progress-card strong{font-size:19px}
body.tablet .progress-line{font-size:14px}
body.tablet .meta{margin-top:8px;font-size:12px}
body.tablet .status .choice-btn{height:auto;min-height:46px;font-size:14px}
}
body.tablet.layout-landscape .wrap{padding:8px}
body.tablet.layout-landscape .panel{width:calc(100vw - 16px);height:calc(100vh - 16px);padding:14px;display:grid;grid-template-columns:minmax(340px,1fr) minmax(300px,.9fr);grid-template-rows:auto auto 1fr auto;gap:8px 14px}
body.tablet.layout-landscape h1{grid-column:1 / 3;font-size:30px;margin:0}
body.tablet.layout-landscape .sub{grid-column:1 / 3;margin:0;font-size:14px}
body.tablet.layout-landscape .display{grid-column:1;height:58px;font-size:36px;margin:0}
body.tablet.layout-landscape .keys{grid-column:1;align-self:start;gap:6px}
body.tablet.layout-landscape button{height:48px;font-size:24px}
body.tablet.layout-landscape .status{grid-column:2;grid-row:3 / 5;height:100%;margin:0;padding:10px;font-size:15px}
body.tablet.layout-landscape .student-photo{width:82px;height:82px}
body.tablet.layout-landscape .progress-card strong{font-size:20px}
body.tablet.layout-landscape .progress-line{font-size:15px}
body.tablet.layout-landscape .motivation{font-size:15px}
body.tablet.layout-landscape .meta{grid-column:1;margin:0;font-size:12px}
body.tablet.layout-landscape .status .choice-btn{height:auto;min-height:48px;font-size:14px}
body.tablet.layout-portrait .wrap{padding:8px}
body.tablet.layout-portrait .panel{width:min(760px,calc(100vw - 16px));height:calc(100vh - 16px);padding:14px}
body.tablet.layout-portrait h1{font-size:30px}
body.tablet.layout-portrait .sub{margin-bottom:10px;font-size:14px}
body.tablet.layout-portrait .display{height:64px;font-size:38px;margin-bottom:8px}
body.tablet.layout-portrait .keys{gap:6px}
body.tablet.layout-portrait button{height:54px;font-size:24px}
body.tablet.layout-portrait .status{height:188px;margin-top:10px;font-size:15px;padding:10px}
body.tablet.layout-portrait .student-photo{width:74px;height:74px}
body.tablet.layout-portrait .progress-card{gap:5px}
body.tablet.layout-portrait .progress-card strong{font-size:19px}
body.tablet.layout-portrait .progress-line{font-size:14px}
body.tablet.layout-portrait .meta{margin-top:8px;font-size:12px}
body.tablet.layout-portrait .status .choice-btn{height:auto;min-height:46px;font-size:14px}
.tablet.layout-landscape .keys{grid-template-rows:repeat(5,minmax(42px,1fr));height:100%}
.tablet.layout-landscape .submit,.tablet.layout-landscape .clear{min-height:44px}
.tablet.layout-landscape .status{min-height:0}
.tablet.layout-portrait .panel{overflow:hidden;display:grid;grid-template-rows:auto auto auto 1fr auto auto}
.tablet.layout-portrait .keys{gap:5px}
.tablet.layout-portrait .status{min-height:0}
.tablet.layout-portrait .progress-card{max-height:100%;overflow:hidden}
.layout-switch{position:fixed;right:12px;top:12px;z-index:50;display:flex;gap:6px}
.layout-switch a{display:inline-flex;align-items:center;justify-content:center;min-height:36px;border:1px solid rgba(255,255,255,.28);border-radius:999px;background:rgba(15,23,42,.82);color:#fff;text-decoration:none;padding:8px 12px;font-size:13px;font-weight:900}
.layout-switch a.active{background:#1769c2;border-color:#1769c2}
body:not(.tablet) .layout-switch{display:none}
</style>
</head>
<body class="<?php echo $tablet_mode ? 'tablet layout-' . $tablet_layout : ''; ?>">
<?php if ($tablet_mode) { ?>
<div class="layout-switch" aria-label="태블릿 화면 방향 선택">
    <a class="<?php echo $tablet_layout === 'landscape' ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/kiosk.php?tablet=1&amp;layout=landscape">가로버전</a>
    <a class="<?php echo $tablet_layout === 'portrait' ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/kiosk.php?tablet=1&amp;layout=portrait">세로버전</a>
</div>
<?php } ?>
<main class="wrap">
    <section class="panel">
        <h1><?php echo get_text($current_academy['academy_name']); ?></h1>
        <p class="sub">학생번호를 입력하고 등원 처리하세요.</p>
        <input id="studentCode" class="display" inputmode="numeric" autocomplete="off" autofocus>
        <div class="keys">
            <button type="button" data-key="1">1</button>
            <button type="button" data-key="2">2</button>
            <button type="button" data-key="3">3</button>
            <button type="button" data-key="4">4</button>
            <button type="button" data-key="5">5</button>
            <button type="button" data-key="6">6</button>
            <button type="button" data-key="7">7</button>
            <button type="button" data-key="8">8</button>
            <button type="button" data-key="9">9</button>
            <button type="button" class="clear" data-action="clear">지움</button>
            <button type="button" data-key="0">0</button>
            <button type="button" class="clear" data-action="back">←</button>
            <button type="button" class="submit" data-action="submit">등원</button>
            <button type="button" class="clear" data-action="reset">초기화</button>
        </div>
        <div id="status" class="status">대기 중</div>
        <div class="meta">로그인: <?php echo get_text($member['mb_name'] ?: $member['mb_id']); ?></div>
    </section>
</main>
<script>
const input = document.getElementById('studentCode');
const statusBox = document.getElementById('status');
let busy = false;
let selectedStudentId = 0;

function setStatus(message, type) {
    statusBox.className = 'status ' + (type || '');
    statusBox.textContent = message;
}

function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));
}

function showAttendanceResult(json) {
    const data = json.data || {};
    const progress = data.progress || null;
    const level = data.character_level || null;
    const status = data.status || '';
    if (status === 'needs_selection') {
        showStudentChoices(data.students || [], json.message || '학생을 선택하세요.');
        return;
    }
    statusBox.className = 'status ' + (json.ok ? (status === 'duplicate' ? 'warn' : 'ok') : 'err');
    if (!progress) {
        statusBox.textContent = json.message;
        return;
    }
    const rate = Math.max(0, Math.min(100, Number(progress.rate) || 0));
    const levelRate = level ? Math.max(0, Math.min(100, Number(level.progress_rate) || 0)) : 0;
    const levelColor = level && level.current ? (level.current.color || '#1947ba') : '#1947ba';
    const levelLabel = level && level.current ? (level.current.label || '') : '';
    const nextText = level ? (Number(level.points_to_next) > 0 ? `다음 레벨까지 ${Number(level.points_to_next).toLocaleString()}점` : '최고 단계 달성') : '';
    statusBox.innerHTML = `
        <div class="progress-card">
            ${data.photo_url ? `<img class="student-photo" src="${escapeHtml(data.photo_url)}" alt="">` : ''}
            ${level ? `
            <div class="level-card">
                <div class="level-badge" style="background:${escapeHtml(levelColor)}">${escapeHtml(levelLabel)}</div>
                <div class="level-copy">
                    <b>${escapeHtml(nextText)}</b>
                    <span>누적 성장 포인트 ${Number(level.points || 0).toLocaleString()}점</span>
                </div>
            </div>
            <div class="progress-bar level-progress"><div class="progress-fill" style="width:${levelRate}%;background:${escapeHtml(levelColor)}"></div></div>` : ''}
            <strong>${escapeHtml(data.student_name || '')} ${status === 'duplicate' ? '이미 등원' : '등원 완료'}</strong>
            <div class="progress-line">이번 달 수련 흐름 ${rate}% · ${progress.attended_days}/${progress.total_scheduled_days}일</div>
            <div class="progress-bar"><div class="progress-fill" style="width:${rate}%"></div></div>
            <div class="progress-line">오늘까지 정상 수업일 기준 ${progress.attended_days}/${progress.elapsed_scheduled_days}일 출석</div>
            <div class="motivation">${escapeHtml(progress.message || '')}</div>
        </div>
    `;
}

function gradeLabel(value) {
    const labels = {
        kindergarten: '유치부',
        elementary_1: '초등 1학년',
        elementary_2: '초등 2학년',
        elementary_3: '초등 3학년',
        elementary_4: '초등 4학년',
        elementary_5: '초등 5학년',
        elementary_6: '초등 6학년',
        middle_1: '중등 1학년',
        middle_2: '중등 2학년',
        middle_3: '중등 3학년',
        high_1: '고등 1학년',
        high_2: '고등 2학년',
        high_3: '고등 3학년'
    };
    return labels[value] || value || '';
}

function birthLabel(value) {
    if (!value || value === '0000-00-00') return '생년월일 미입력';
    return value.replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$1년 $2월 $3일');
}

function showStudentChoices(students, message) {
    statusBox.className = 'status warn';
    const items = students.map((student) => `
        <button type="button" class="choice-btn" data-student-id="${Number(student.student_id) || 0}">
            ${student.photo_url ? `<img src="${escapeHtml(student.photo_url)}" alt="">` : '<span class="choice-thumb">사진</span>'}
            <span>
                <strong>${escapeHtml(student.student_name || '')}</strong>
                <span>${escapeHtml(birthLabel(student.birth_date))} · ${escapeHtml(gradeLabel(student.grade_group))}</span>
            </span>
        </button>
    `).join('');
    statusBox.innerHTML = `<div class="choice-list"><div class="motivation">${escapeHtml(message)}</div>${items}</div>`;
}

function resetInput() {
    input.value = '';
    selectedStudentId = 0;
    input.focus();
}

async function submitAttendance() {
    const code = input.value.trim();
    if (!code || busy) {
        return;
    }

    busy = true;
    setStatus('처리 중입니다.', '');

    const body = new URLSearchParams();
    body.set('student_code', code);
    body.set('csrf_token', '<?php echo $token; ?>');
    if (selectedStudentId) {
        body.set('student_id', String(selectedStudentId));
    }

    try {
        const res = await fetch('<?php echo IEUM_URL; ?>/save_attendance_by_code.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body
        });
        const json = await res.json();
        showAttendanceResult(json);
        if (json.ok && (!json.data || json.data.status !== 'needs_selection')) {
            resetInput();
        }
    } catch (e) {
        setStatus('서버 응답을 확인할 수 없습니다.', 'err');
    } finally {
        busy = false;
        input.focus();
    }
}

document.querySelector('.keys').addEventListener('click', (event) => {
    const button = event.target.closest('button');
    if (!button) return;

    const key = button.dataset.key;
    const action = button.dataset.action;

    if (key) input.value += key;
    if (action === 'clear' || action === 'reset') {
        resetInput();
        setStatus('대기 중', '');
    }
    if (action === 'back') input.value = input.value.slice(0, -1);
    if (action === 'submit') submitAttendance();

    input.focus();
});

statusBox.addEventListener('click', (event) => {
    const button = event.target.closest('.choice-btn');
    if (!button) return;
    selectedStudentId = Number(button.dataset.studentId) || 0;
    submitAttendance();
});

input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        submitAttendance();
    }
});
</script>
</body>
</html>
