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
body.tablet{background:#111827}
.wrap{min-height:100vh;display:grid;place-items:center;padding:24px}
.panel{width:min(560px,100%);background:#fff;border:1px solid #dde1e7;border-radius:8px;padding:28px;box-shadow:0 10px 30px rgba(15,23,42,.08)}
body.tablet .panel{width:min(760px,100%);padding:40px}
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
.status{min-height:58px;margin-top:18px;padding:14px;border-radius:8px;background:#eef2f7;color:#1f2937;font-size:18px}
.status.ok{background:#e8f7ee;color:#146c2e}
.status.warn{background:#fff4e6;color:#9a5b00}
.status.err{background:#fdecec;color:#a4262c}
.progress-card{display:grid;gap:8px}
.student-photo{width:120px;height:120px;border-radius:999px;object-fit:cover;border:4px solid #fff;box-shadow:0 6px 18px rgba(15,23,42,.18);margin:0 auto 4px}
.progress-card strong{font-size:24px;color:#111827}
.progress-line{font-size:18px;color:#334155}
.progress-bar{height:12px;background:#dbe4ef;border-radius:999px;overflow:hidden}
.progress-fill{height:100%;background:#1769c2;border-radius:999px}
.motivation{font-weight:800;color:#146c2e}
.meta{margin-top:12px;color:#697386;font-size:14px}
</style>
</head>
<body class="<?php echo $tablet_mode ? 'tablet' : ''; ?>">
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
    const status = data.status || '';
    statusBox.className = 'status ' + (json.ok ? (status === 'duplicate' ? 'warn' : 'ok') : 'err');
    if (!progress) {
        statusBox.textContent = json.message;
        return;
    }
    const rate = Math.max(0, Math.min(100, Number(progress.rate) || 0));
    statusBox.innerHTML = `
        <div class="progress-card">
            ${data.photo_url ? `<img class="student-photo" src="${escapeHtml(data.photo_url)}" alt="">` : ''}
            <strong>${escapeHtml(data.student_name || '')} ${status === 'duplicate' ? '이미 등원' : '등원 완료'}</strong>
            <div class="progress-line">이번 달 등원 ${rate}% · ${progress.attended_days}/${progress.total_scheduled_days}일</div>
            <div class="progress-bar"><div class="progress-fill" style="width:${rate}%"></div></div>
            <div class="progress-line">현재 정상 수업일 기준 ${progress.attended_days}/${progress.elapsed_scheduled_days}일 출석</div>
            <div class="motivation">${escapeHtml(progress.message || '')}</div>
        </div>
    `;
}

function resetInput() {
    input.value = '';
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

    try {
        const res = await fetch('<?php echo IEUM_URL; ?>/save_attendance_by_code.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body
        });
        const json = await res.json();
        showAttendanceResult(json);
        if (json.ok) {
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

input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        submitAttendance();
    }
});
</script>
</body>
</html>
