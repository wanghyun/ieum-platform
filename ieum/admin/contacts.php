<?php
$sub_menu = '950160';
require_once './_common.php';

$g5['title'] = '아이이음 알림 담당자';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

function ieum_contact_clean_phone($phone)
{
    return preg_replace('/[^0-9+\-]/', '', trim($phone));
}

function ieum_contacts_ensure_columns()
{
    $columns = array(
        'sms_vehicle_alert' => "alter table " . IEUM_ACADEMY_CONTACT_TABLE . " add sms_vehicle_alert tinyint(1) not null default 1 after sms_absent_alert",
        'sms_memo_alert' => "alter table " . IEUM_ACADEMY_CONTACT_TABLE . " add sms_memo_alert tinyint(1) not null default 1 after sms_system_alert",
        'sms_member_alert' => "alter table " . IEUM_ACADEMY_CONTACT_TABLE . " add sms_member_alert tinyint(1) not null default 1 after sms_memo_alert",
        'sms_counseling_alert' => "alter table " . IEUM_ACADEMY_CONTACT_TABLE . " add sms_counseling_alert tinyint(1) not null default 1 after sms_member_alert",
        'sms_tuition_alert' => "alter table " . IEUM_ACADEMY_CONTACT_TABLE . " add sms_tuition_alert tinyint(1) not null default 1 after sms_counseling_alert",
        'sms_report_alert' => "alter table " . IEUM_ACADEMY_CONTACT_TABLE . " add sms_report_alert tinyint(1) not null default 1 after sms_tuition_alert",
    );

    foreach ($columns as $column => $sql) {
        $exists = sql_fetch("show columns from " . IEUM_ACADEMY_CONTACT_TABLE . " like '" . sql_escape_string($column) . "'", false);
        if (empty($exists['Field'])) {
            sql_query($sql, false);
        }
    }
}

ieum_contacts_ensure_columns();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $contact_id = isset($_POST['contact_id']) ? (int) $_POST['contact_id'] : 0;
        $contact_name = isset($_POST['contact_name']) ? trim($_POST['contact_name']) : '';
        $contact_phone = isset($_POST['contact_phone']) ? ieum_contact_clean_phone($_POST['contact_phone']) : '';
        $sms_absent_alert = isset($_POST['sms_absent_alert']) ? 1 : 0;
        $sms_vehicle_alert = isset($_POST['sms_vehicle_alert']) ? 1 : 0;
        $sms_system_alert = isset($_POST['sms_system_alert']) ? 1 : 0;
        $sms_memo_alert = isset($_POST['sms_memo_alert']) ? 1 : 0;
        $sms_member_alert = isset($_POST['sms_member_alert']) ? 1 : 0;
        $sms_counseling_alert = isset($_POST['sms_counseling_alert']) ? 1 : 0;
        $sms_tuition_alert = isset($_POST['sms_tuition_alert']) ? 1 : 0;
        $sms_report_alert = isset($_POST['sms_report_alert']) ? 1 : 0;
        $sort_order = isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($contact_name === '') {
            $error = '담당자 이름을 입력하세요.';
        } elseif ($contact_phone === '') {
            $error = '담당자 연락처를 입력하세요.';
        } else {
            $contact_name_sql = sql_escape_string($contact_name);
            $contact_phone_sql = sql_escape_string($contact_phone);

            if ($contact_id) {
                sql_query("
                    update " . IEUM_ACADEMY_CONTACT_TABLE . "
                       set contact_name = '{$contact_name_sql}',
                           contact_phone = '{$contact_phone_sql}',
                           sms_absent_alert = '{$sms_absent_alert}',
                           sms_vehicle_alert = '{$sms_vehicle_alert}',
                           sms_system_alert = '{$sms_system_alert}',
                           sms_memo_alert = '{$sms_memo_alert}',
                           sms_member_alert = '{$sms_member_alert}',
                           sms_counseling_alert = '{$sms_counseling_alert}',
                           sms_tuition_alert = '{$sms_tuition_alert}',
                           sms_report_alert = '{$sms_report_alert}',
                           sort_order = '{$sort_order}',
                           is_active = '{$is_active}',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where contact_id = '{$contact_id}'
                       and academy_id = '{$academy_id}'
                ");
                $message = '알림 담당자 정보가 수정되었습니다.';
            } else {
                sql_query("
                    insert into " . IEUM_ACADEMY_CONTACT_TABLE . "
                        set academy_id = '{$academy_id}',
                            contact_name = '{$contact_name_sql}',
                            contact_phone = '{$contact_phone_sql}',
                            sms_absent_alert = '{$sms_absent_alert}',
                            sms_vehicle_alert = '{$sms_vehicle_alert}',
                            sms_system_alert = '{$sms_system_alert}',
                            sms_memo_alert = '{$sms_memo_alert}',
                            sms_member_alert = '{$sms_member_alert}',
                            sms_counseling_alert = '{$sms_counseling_alert}',
                            sms_tuition_alert = '{$sms_tuition_alert}',
                            sms_report_alert = '{$sms_report_alert}',
                            sort_order = '{$sort_order}',
                            is_active = '{$is_active}',
                            created_at = '" . G5_TIME_YMDHIS . "'
                ");
                $message = '알림 담당자가 등록되었습니다.';
            }
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$rows = sql_query("
    select *
      from " . IEUM_ACADEMY_CONTACT_TABLE . "
     where academy_id = '{$academy_id}'
  order by sort_order asc, contact_id asc
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
.wrap{max-width:1900px;margin:28px auto;padding:0 20px}body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}
h1{margin:0 0 8px;font-size:30px}.meta{color:#667085;margin-bottom:16px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}
.guide{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px}.guide-card{border:1px solid #d9dee7;border-radius:8px;padding:14px;background:#f8fafc}.guide-card strong{display:block;margin-bottom:6px;color:#1769c2}
input{border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}.add-grid{display:grid;grid-template-columns:repeat(16,minmax(0,1fr));gap:10px;align-items:center}.add-grid input[name=contact_name]{grid-column:span 3}.add-grid input[name=contact_phone]{grid-column:span 3}.add-grid input[name=sort_order]{grid-column:span 1}.add-grid .check{grid-column:span 1}.add-grid .primary{grid-column:span 1}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:800;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}
.check{display:inline-flex;align-items:center;gap:6px;white-space:nowrap;font-weight:800;color:#344054}.check input{width:auto}.badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:900;background:#eef2f7;color:#344054}.badge.on{background:#e8f7ee;color:#176b2c}.badge.off{background:#fdecec;color:#a4262c}
table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center}th{background:#72829d;color:#fff}td input[type=text],td input[type=number]{width:100%}.help{margin-top:10px;color:#667085;font-size:13px;line-height:1.55}
.table-scroll{max-width:100%;overflow-x:auto;padding-bottom:2px}
.table-scroll table{min-width:1280px}
.table-scroll th,.table-scroll td{padding:8px}
.table-scroll .check{justify-content:center;font-size:13px}
@media(max-width:1280px){.add-grid input[name=contact_name],.add-grid input[name=contact_phone]{grid-column:span 6}.add-grid input[name=sort_order],.add-grid .check,.add-grid .primary{grid-column:span 2}}
@media(max-width:980px){.add-grid,.guide{grid-template-columns:1fr}.add-grid input[name=contact_name],.add-grid input[name=contact_phone],.add-grid input[name=sort_order],.add-grid .check,.add-grid .primary{grid-column:auto}.table-scroll{overflow-x:auto}table{min-width:1320px}}
body.ieum-side-layout.ieum-dashboard-page.contacts-page-tune{--ieum-side-width:260px;--ieum-top-height:64px;--ieum-rail-width:0px;--ieum-shell-top:#fff;background:#f5f7fb!important;color:#111827!important}
body.ieum-side-layout.ieum-dashboard-page.contacts-page-tune .ieum-side{width:260px!important;background:#fff!important;border-right:1px solid #e5e7eb!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.contacts-page-tune .side-brand{height:144px!important;padding:0 28px!important;align-items:center!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.contacts-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.contacts-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.contacts-page-tune .side-search,
body.ieum-side-layout.ieum-dashboard-page.contacts-page-tune .ieum-right-rail{display:none!important}
.contacts-page-tune .side-nav{padding:0 14px 22px!important}.contacts-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}.contacts-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}.contacts-page-tune .ieum-shell-top{left:260px!important;right:0!important;height:64px!important;background:#fff!important;border-bottom:1px solid #eef2f7!important;color:#0f172a!important;box-shadow:none!important}.contacts-page-tune .ieum-shell-link,.contacts-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}.contacts-page-tune .ieum-shell-link::before{display:none!important}.contacts-page-tune .ieum-shell-meta{color:#0f172a!important}.contacts-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.contacts-page-tune .dashboard-shell-divider,.contacts-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.contacts-page-tune .wrap{max-width:none!important;width:auto!important;margin:0 0 0 260px!important;padding:96px 40px 42px!important}
@media(max-width:980px){body.ieum-side-layout.ieum-dashboard-page.contacts-page-tune{--ieum-side-width:0px}.contacts-page-tune .ieum-shell-top{left:0!important}body.ieum-side-layout.ieum-dashboard-page.contacts-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page ieum-simple-page contacts-page-tune">
<?php echo ieum_admin_header('contacts', 'side'); ?>
<main class="wrap">
    <h1>알림 담당자</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 미등원, 차량 특이사항, 시스템, 메모 알림을 받을 내부 담당자 연락처입니다.</div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="panel">
        <h2>담당자 추가</h2>
        <form method="post" class="add-grid">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="text" name="contact_name" placeholder="예: 관장님" required>
            <input type="text" name="contact_phone" placeholder="010-0000-0000" required>
            <input type="number" name="sort_order" value="1" min="0" title="정렬">
            <label class="check"><input type="checkbox" name="sms_absent_alert" value="1" checked> 미등원</label>
            <label class="check"><input type="checkbox" name="sms_vehicle_alert" value="1" checked> 차량</label>
            <label class="check"><input type="checkbox" name="sms_system_alert" value="1" checked> 시스템</label>
            <label class="check"><input type="checkbox" name="sms_memo_alert" value="1" checked> 메모</label>
            <label class="check"><input type="checkbox" name="sms_member_alert" value="1" checked> 회원</label>
            <label class="check"><input type="checkbox" name="sms_counseling_alert" value="1" checked> 상담</label>
            <label class="check"><input type="checkbox" name="sms_tuition_alert" value="1" checked> 수련비</label>
            <label class="check"><input type="checkbox" name="sms_report_alert" value="1" checked> 리포트</label>
            <label class="check"><input type="checkbox" name="is_active" value="1" checked> 사용</label>
            <button type="submit" class="btn primary">추가</button>
        </form>
        <div class="guide">
            <div class="guide-card"><strong>미등원 알림</strong>수업 시작 후 설정 시간까지 등원하지 않은 원생을 알려줍니다.</div>
            <div class="guide-card"><strong>차량 알림</strong>기사님이 미탑승, 보호자 통화, 메모를 남기면 바로 확인할 수 있습니다.</div>
            <div class="guide-card"><strong>시스템 알림</strong>운영 중 꼭 봐야 하는 내부 안내용 알림입니다.</div>
            <div class="guide-card"><strong>메모/원생관리</strong>월간 스케줄 메모와 원생관리 신호를 내부 담당자 기준으로 분리해 받을 수 있습니다.</div>
        </div>
    </section>

    <section class="panel">
        <h2>등록된 담당자</h2>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>담당자</th>
                        <th>연락처</th>
                        <th>정렬</th>
                        <th>미등원</th>
                        <th>차량</th>
                        <th>시스템</th>
                        <th>메모</th>
                        <th>회원</th>
                        <th>상담</th>
                        <th>수련비</th>
                        <th>리포트</th>
                        <th>상태</th>
                        <th>수정</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i = 0; while ($row = sql_fetch_array($rows)) { $i++; ?>
                <tr>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="contact_id" value="<?php echo (int) $row['contact_id']; ?>">
                        <td><input type="text" name="contact_name" value="<?php echo get_text($row['contact_name']); ?>"></td>
                        <td><input type="text" name="contact_phone" value="<?php echo get_text($row['contact_phone']); ?>"></td>
                        <td><input type="number" name="sort_order" value="<?php echo (int) $row['sort_order']; ?>"></td>
                        <td><label class="check"><input type="checkbox" name="sms_absent_alert" value="1" <?php echo $row['sms_absent_alert'] ? 'checked' : ''; ?>> 사용</label></td>
                        <td><label class="check"><input type="checkbox" name="sms_vehicle_alert" value="1" <?php echo $row['sms_vehicle_alert'] ? 'checked' : ''; ?>> 사용</label></td>
                        <td><label class="check"><input type="checkbox" name="sms_system_alert" value="1" <?php echo $row['sms_system_alert'] ? 'checked' : ''; ?>> 사용</label></td>
                        <td><label class="check"><input type="checkbox" name="sms_memo_alert" value="1" <?php echo !empty($row['sms_memo_alert']) ? 'checked' : ''; ?>> 사용</label></td>
                        <td><label class="check"><input type="checkbox" name="sms_member_alert" value="1" <?php echo !isset($row['sms_member_alert']) || !empty($row['sms_member_alert']) ? 'checked' : ''; ?>> 사용</label></td>
                        <td><label class="check"><input type="checkbox" name="sms_counseling_alert" value="1" <?php echo !isset($row['sms_counseling_alert']) || !empty($row['sms_counseling_alert']) ? 'checked' : ''; ?>> 사용</label></td>
                        <td><label class="check"><input type="checkbox" name="sms_tuition_alert" value="1" <?php echo !isset($row['sms_tuition_alert']) || !empty($row['sms_tuition_alert']) ? 'checked' : ''; ?>> 사용</label></td>
                        <td><label class="check"><input type="checkbox" name="sms_report_alert" value="1" <?php echo !isset($row['sms_report_alert']) || !empty($row['sms_report_alert']) ? 'checked' : ''; ?>> 사용</label></td>
                        <td><label class="check"><input type="checkbox" name="is_active" value="1" <?php echo $row['is_active'] ? 'checked' : ''; ?>> 사용</label></td>
                        <td><button type="submit" class="btn">저장</button></td>
                    </form>
                </tr>
                <?php } ?>
                <?php if ($i === 0) { ?><tr><td colspan="13">등록된 담당자가 없습니다. 관장님 또는 사범님 연락처를 먼저 등록하세요.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
        <p class="help">차량 알림은 기사님 앱이나 탑승 확인 화면에서 미탑승, 보호자 통화, 메모가 기록될 때 문자 발송 준비로 등록됩니다. 메모 알림은 대시보드 월간 스케줄의 예약 시간에 맞춰 발송 준비됩니다. 원생관리의 생일/장기 미등원 안부 문자는 원생 보호자 연락처로 발송 준비됩니다. 원생관리, 상담, 수련비, 리포트 체크는 차후 내부 담당자 알림을 세분화할 기준입니다. 실제 발송은 문자 발송폰이 실행 중일 때 진행됩니다.</p>
    </section>
</main>
<script>
(function(){
    var rootSelector = '.contacts-page-tune.ieum-dashboard-page';
    var brandText = document.querySelector(rootSelector + ' .side-brand span:last-child');
    if (brandText) brandText.textContent = <?php echo json_encode($academy['academy_name']); ?>;
    var homeLink = document.querySelector(rootSelector + ' .ieum-shell-link');
    if (homeLink) homeLink.textContent = '아이이음 교육페이지';
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
</body>
</html>
