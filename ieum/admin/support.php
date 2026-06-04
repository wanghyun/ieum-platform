<?php
$sub_menu = '950190';
require_once './_common.php';

$g5['title'] = '아이이음 개발지원센터';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

function ieum_support_inquiry_table()
{
    return 'ieum_support_inquiry';
}

function ieum_support_ensure_table()
{
    $engine = defined('G5_DB_ENGINE') && G5_DB_ENGINE ? G5_DB_ENGINE : 'InnoDB';
    $charset = defined('G5_DB_CHARSET') && G5_DB_CHARSET ? G5_DB_CHARSET : 'utf8';
    sql_query("
        create table if not exists " . ieum_support_inquiry_table() . " (
            inquiry_id int unsigned not null auto_increment,
            academy_id int unsigned not null default 0,
            category varchar(30) not null default 'contact',
            title varchar(120) not null default '',
            body text null,
            contact_name varchar(60) not null default '',
            contact_phone varchar(40) not null default '',
            status varchar(20) not null default 'open',
            created_by varchar(50) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            primary key (inquiry_id),
            key idx_academy_status (academy_id, status, inquiry_id),
            key idx_created (created_at)
        ) engine={$engine} default charset={$charset}
    ", false);
}

function ieum_support_topic_label($topic)
{
    $labels = array(
        'qna' => 'Q&A',
        'faq' => '자주하는 질문',
        'contact' => '문의하기',
        'ai' => 'AI 챗봇',
    );
    return isset($labels[$topic]) ? $labels[$topic] : $labels['qna'];
}

ieum_support_ensure_table();

$topic = isset($_GET['topic']) ? preg_replace('/[^a-z]/', '', trim($_GET['topic'])) : 'qna';
if (!in_array($topic, array('qna', 'faq', 'contact', 'ai'), true)) {
    $topic = 'qna';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    $action = isset($_POST['action']) ? preg_replace('/[^a-z_]/', '', $_POST['action']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } elseif ($action === 'submit_inquiry') {
        $category = isset($_POST['category']) ? preg_replace('/[^a-z]/', '', trim($_POST['category'])) : 'contact';
        if (!in_array($category, array('qna', 'faq', 'contact', 'ai'), true)) {
            $category = 'contact';
        }
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $body = isset($_POST['body']) ? trim($_POST['body']) : '';
        $contact_name = isset($_POST['contact_name']) ? trim($_POST['contact_name']) : '';
        $contact_phone = isset($_POST['contact_phone']) ? preg_replace('/[^0-9+\-]/', '', trim($_POST['contact_phone'])) : '';

        if ($title === '') {
            $error = '문의 제목을 입력해 주세요.';
            $topic = 'contact';
        } elseif ($body === '') {
            $error = '문의 내용을 입력해 주세요.';
            $topic = 'contact';
        } else {
            sql_query("
                insert into " . ieum_support_inquiry_table() . "
                    set academy_id = '{$academy_id}',
                        category = '" . sql_escape_string($category) . "',
                        title = '" . sql_escape_string($title) . "',
                        body = '" . sql_escape_string($body) . "',
                        contact_name = '" . sql_escape_string($contact_name) . "',
                        contact_phone = '" . sql_escape_string($contact_phone) . "',
                        status = 'open',
                        created_by = '" . sql_escape_string(isset($member['mb_id']) ? $member['mb_id'] : '') . "',
                        created_at = '" . G5_TIME_YMDHIS . "'
            ", false);
            $message = '문의가 접수되었습니다. 개발지원센터에서 확인할 수 있도록 기록했습니다.';
            $topic = 'contact';
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$recent_inquiries = sql_query("
    select inquiry_id, category, title, status, created_at
      from " . ieum_support_inquiry_table() . "
     where academy_id = '{$academy_id}'
  order by inquiry_id desc
     limit 6
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
.wrap{max-width:1900px;margin:28px auto;padding:0 20px}body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}
.page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:18px}.page-head h1{margin:0 0 8px;font-size:30px}.page-head p{margin:0;color:#667085}
.support-tabs{display:flex;gap:8px;flex-wrap:wrap}.support-tabs a{display:inline-flex;align-items:center;min-height:36px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;color:#243142;text-decoration:none;padding:0 12px;font-size:13px;font-weight:900}.support-tabs a.active,.support-tabs a:hover{background:#1769c2;border-color:#1769c2;color:#fff}
.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}.panel h2{margin:0 0 10px;font-size:22px}.panel h3{margin:0 0 8px;font-size:16px}.panel p{color:#667085;line-height:1.6}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}
.support-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.support-card{border:1px solid #e1e7f0;border-radius:8px;background:#fbfdff;padding:16px}.support-card strong{display:block;margin-bottom:7px;color:#1769c2;font-size:15px}.support-card a{color:#1769c2;font-weight:900;text-decoration:none}
.support-card p{margin:0 0 8px}.support-card ul{margin:8px 0 0;padding-left:18px;color:#475467;line-height:1.65}.support-card li+li{margin-top:3px}.support-card .card-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:12px}
.qa-stack{display:grid;gap:12px}.qa-item{border:1px solid #e1e7f0;border-radius:8px;background:#fff;padding:16px}.qa-item h3{display:flex;align-items:center;gap:8px;color:#111827}.qa-item h3:before{content:"Q";display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#eaf4ff;color:#1769c2;font-size:12px;font-weight:1000}.qa-item p{margin:0}.qa-item .answer{border-top:1px solid #edf1f7;margin-top:10px;padding-top:10px;color:#475467}.qa-item .answer:before{content:"A";display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#eef9f1;color:#176b2c;font-size:11px;font-weight:1000;margin-right:7px}
.guide-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.guide-card{border:1px solid #d9dee7;border-radius:8px;background:#fff;padding:16px}.guide-card h3{margin-bottom:10px}.guide-card ol{margin:0;padding-left:19px;color:#475467;line-height:1.7}.guide-card a{color:#1769c2;font-weight:900;text-decoration:none}
.flow{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.flow-card{border:1px solid #d9dee7;border-radius:8px;padding:14px;background:#fff}.flow-card b{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:#1769c2;color:#fff;margin-bottom:8px}
.chat-shell{display:grid;grid-template-columns:minmax(280px,.42fr) minmax(0,1fr);gap:16px}.chat-side{border:1px solid #e1e7f0;border-radius:8px;background:#fbfdff;padding:16px}.chat-side h3{margin-bottom:10px}.chat-chip-list{display:flex;gap:8px;flex-wrap:wrap}.chat-chip{border:1px solid #cfd8e3;border-radius:999px;background:#fff;color:#243142;padding:8px 11px;font-size:12px;font-weight:900;cursor:pointer}.chat-chip:hover{border-color:#1769c2;color:#1769c2;background:#f4f8ff}.chat-box{border:1px solid #d9dee7;border-radius:8px;background:#fff;min-height:520px;display:flex;flex-direction:column;overflow:hidden}.chat-log{flex:1;display:grid;align-content:start;gap:10px;padding:16px;overflow:auto;background:#f8fafc}.chat-msg{display:grid;gap:6px;max-width:82%;border:1px solid #e1e7f0;border-radius:12px;padding:12px;background:#fff;color:#243142}.chat-msg.user{justify-self:end;background:#1769c2;border-color:#1769c2;color:#fff}.chat-msg.bot{justify-self:start}.chat-msg strong{font-size:13px}.chat-msg p{margin:0;color:inherit}.chat-links{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}.chat-links a{display:inline-flex;align-items:center;min-height:28px;border:1px solid #cfd8e3;border-radius:999px;background:#fff;color:#1769c2;text-decoration:none;padding:0 10px;font-size:12px;font-weight:900}.chat-form{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;border-top:1px solid #e1e7f0;padding:12px;background:#fff}.chat-form input{width:100%;border:1px solid #cfd6df;border-radius:8px;padding:0 12px;font-size:14px}.chat-form .btn{min-height:42px}
.inquiry-form{display:grid;grid-template-columns:1fr 1fr;gap:10px}.inquiry-form label{display:grid;gap:6px;font-size:13px;font-weight:900;color:#344054}.inquiry-form input,.inquiry-form select,.inquiry-form textarea{width:100%;border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:14px}.inquiry-form textarea{min-height:150px;resize:vertical}.inquiry-form .wide{grid-column:1/-1}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #1769c2;border-radius:6px;background:#1769c2;color:#fff;text-decoration:none;padding:0 15px;font-weight:900;cursor:pointer}
.recent-list{display:grid;gap:8px}.recent-row{display:grid;grid-template-columns:94px minmax(0,1fr) 96px 140px;gap:10px;align-items:center;min-height:38px;border-top:1px solid #e8edf3;color:#344054;font-size:13px}.recent-row:first-child{border-top:0}.badge{display:inline-flex;align-items:center;justify-content:center;min-height:24px;border-radius:999px;background:#eef2f7;color:#344054;padding:0 9px;font-size:12px;font-weight:900}.badge.open{background:#fff4df;color:#9a5b00}
@media(max-width:1280px){.guide-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.chat-shell{grid-template-columns:1fr}}
@media(max-width:980px){.page-head{display:block}.support-grid,.flow,.inquiry-form,.guide-grid{grid-template-columns:1fr}.recent-row{grid-template-columns:1fr}.support-tabs{margin-top:12px}.chat-form{grid-template-columns:1fr}.chat-msg{max-width:100%}}
body.ieum-side-layout.ieum-dashboard-page.support-page-tune{--ieum-side-width:260px;--ieum-top-height:64px;--ieum-rail-width:0px;--ieum-shell-top:#fff;background:#f5f7fb!important;color:#111827!important}
body.ieum-side-layout.ieum-dashboard-page.support-page-tune .ieum-side{width:260px!important;background:#fff!important;border-right:1px solid #e5e7eb!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.support-page-tune .side-brand{height:144px!important;padding:0 28px!important;align-items:center!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.support-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.support-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.support-page-tune .side-search,
body.ieum-side-layout.ieum-dashboard-page.support-page-tune .ieum-right-rail{display:none!important}
.support-page-tune .side-nav{padding:0 14px 22px!important}.support-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}.support-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}.support-page-tune .ieum-shell-top{left:260px!important;right:0!important;height:64px!important;background:#fff!important;border-bottom:1px solid #eef2f7!important;color:#0f172a!important;box-shadow:none!important}.support-page-tune .ieum-shell-link,.support-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}.support-page-tune .ieum-shell-link::before{display:none!important}.support-page-tune .ieum-shell-meta{color:#0f172a!important}.support-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.support-page-tune .dashboard-shell-divider,.support-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.support-page-tune .wrap{max-width:none!important;width:auto!important;margin:0 0 0 260px!important;padding:96px 40px 42px!important}
@media(max-width:980px){body.ieum-side-layout.ieum-dashboard-page.support-page-tune{--ieum-side-width:0px}.support-page-tune .ieum-shell-top{left:0!important}body.ieum-side-layout.ieum-dashboard-page.support-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page support-page support-page-tune">
<?php echo ieum_admin_header('support', 'side'); ?>
<main class="wrap">
    <div class="page-head">
        <div>
            <h1>개발지원센터</h1>
            <p><?php echo get_text($academy['academy_name']); ?> · 대시보드, 수련비, 문자 발송, 원생관리 기능을 바로 확인합니다.</p>
        </div>
        <nav class="support-tabs" aria-label="지원센터 메뉴">
            <?php foreach (array('qna', 'faq', 'contact', 'ai') as $tab) { ?>
            <a class="<?php echo $topic === $tab ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/support.php?topic=<?php echo $tab; ?>"><?php echo get_text(ieum_support_topic_label($tab)); ?></a>
            <?php } ?>
        </nav>
    </div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <?php if ($topic === 'qna') { ?>
    <section class="panel">
        <h2>Q&A</h2>
        <div class="qa-stack">
            <article class="qa-item">
                <h3>대시보드의 미등원 숫자는 무엇을 기준으로 계산하나요?</h3>
                <p class="answer">오늘 요일에 수업이 잡힌 재원생 중, 오늘 출석 기록이 없는 원생입니다. 공휴일이나 도장 휴관일은 정상 수업일에서 제외되므로 미등원 대상도 0명으로 계산됩니다. 확인은 <a href="<?php echo IEUM_URL; ?>/admin/attendance_today.php">오늘 출석 관리</a>에서 이어갑니다.</p>
            </article>
            <article class="qa-item">
                <h3>공휴일이나 선거일은 수업에서 자동 제외되나요?</h3>
                <p class="answer">네. 공휴일은 자동 휴관으로 적용되어 오늘 알림, 오늘 수업, 미등원 계산에서 제외됩니다. 도장 자체 휴관이나 보충 수업은 <a href="<?php echo IEUM_URL; ?>/admin/school_calendar.php">수업일/휴관일</a>에서 따로 등록합니다.</p>
            </article>
            <article class="qa-item">
                <h3>업무 바로가기는 어떻게 추가하나요?</h3>
                <p class="answer">왼쪽 메뉴의 하위 메뉴 옆 별표를 누르면 대시보드 보조 정보의 업무 바로가기에 들어갑니다. 최대 6개까지 고를 수 있고, 모두 해제하면 기본 바로가기가 다시 복구됩니다.</p>
            </article>
            <article class="qa-item">
                <h3>오늘 수업 카드에서는 무엇을 먼저 보면 되나요?</h3>
                <p class="answer">시간, 수업 부, 진행 상태, 출석 인원, 미등원 인원을 한 줄로 봅니다. 미등원이 남아 있으면 해당 부를 눌러 원생별 확인으로 들어가거나, 카드 하단의 출석 처리에서 부를 선택해 일괄 처리합니다.</p>
            </article>
            <article class="qa-item">
                <h3>월간 스케줄 메모와 문자 알림은 어떻게 쓰나요?</h3>
                <p class="answer">달력에서 날짜를 누르고 메모를 저장하면 해당 날짜 칸에 짧게 표시됩니다. 문자 알림을 체크하면 담당자에게 당일, 하루 전, 2일 전처럼 원하는 시점에 운영 메모를 알려줄 수 있습니다.</p>
            </article>
            <article class="qa-item">
                <h3>승급증은 어디에서 인쇄하나요?</h3>
                <p class="answer">먼저 <a href="<?php echo IEUM_URL; ?>/admin/promotion_targets.php">승급 대상</a>에서 원생을 승급 처리합니다. 처리된 원생은 <a href="<?php echo IEUM_URL; ?>/admin/promotion_certificates.php">승급증 인쇄</a> 화면의 인쇄 대기 목록에 모이고, 원생을 선택해 미리보기 후 인쇄 완료로 표시합니다.</p>
            </article>
            <article class="qa-item">
                <h3>장기 미등원과 오늘 미등원은 어떻게 다른가요?</h3>
                <p class="answer">오늘 미등원은 오늘 수업에 아직 오지 않은 원생이고, 장기 미등원은 최근 14일 이상 출석 기록이 없는 원생입니다. 장기 미등원은 안부 연락이나 상담 후 확인 처리하면 대시보드 숫자에서 빠집니다.</p>
            </article>
            <article class="qa-item">
                <h3>수련비 미납이 있는데 대시보드가 0명으로 보이면 어디를 봐야 하나요?</h3>
                <p class="answer">대시보드는 이번 달 수련비 청구 내역에서 아직 완납되지 않은 원생을 봅니다. 먼저 <a href="<?php echo IEUM_URL; ?>/admin/tuition_payments.php?payment_filter=unpaid#paymentList">수련비 미납 목록</a>에서 이번 달 청구가 만들어져 있는지, 납부완료로 잘못 처리되어 있지 않은지 확인합니다.</p>
            </article>
            <article class="qa-item">
                <h3>납부 예정과 수련비 미납은 왜 숫자가 다르게 나오나요?</h3>
                <p class="answer">수련비 미납은 이번 달 청구 중 아직 완납되지 않은 전체 원생입니다. 납부 예정은 그중 납부일이 오늘이거나 앞으로 다가오는 원생입니다. 예를 들어 오늘이 3일이고 납부일이 5일이면 납부 예정에 표시됩니다.</p>
            </article>
            <article class="qa-item">
                <h3>원생관리에서 이름을 누르면 무엇을 볼 수 있나요?</h3>
                <p class="answer">원생 프로필이 열리고, 관리 신호, 다음 케어, 처리 바로가기, 수업, 수련비, 문자/알림 흐름, 최근 처리 기록을 한 번에 볼 수 있습니다. 출석, 수련비, 문자, 리포트, 차량 업무는 프로필 안의 처리 바로가기에서 이어갑니다.</p>
            </article>
            <article class="qa-item">
                <h3>연락처 누락은 어떤 원생인가요?</h3>
                <p class="answer">활성 원생인데 사용 중인 보호자 연락처가 없는 원생입니다. 원생관리에서 해당 원생을 열어 보호자 연락처를 추가하면 리포트 발송, 문자 안내, 미등원 연락 흐름이 정상화됩니다.</p>
            </article>
            <article class="qa-item">
                <h3>월말 마감의 0/250은 원생 수인가요?</h3>
                <p class="answer">아닙니다. 인성 리포트 준비 건수와 체력 리포트 준비 건수를 합친 값입니다. 그래서 단위는 명이 아니라 건으로 봅니다. 실제 마감은 <a href="<?php echo IEUM_URL; ?>/admin/monthly_close.php">월말 마감</a>에서 확인합니다.</p>
            </article>
            <article class="qa-item">
                <h3>문자 실패는 어디에서 다시 확인하나요?</h3>
                <p class="answer">발송을 시도했지만 실패한 문자를 보여줍니다. 발송폰 연결, 자동 발송 시간, 실패 사유는 <a href="<?php echo IEUM_URL; ?>/admin/sms_queue.php?status=failed">문자 발송현황</a>과 <a href="<?php echo IEUM_URL; ?>/admin/sms_devices.php">문자 발송폰 관리</a>에서 확인합니다.</p>
            </article>
            <article class="qa-item">
                <h3>AI 챗봇에는 어떻게 물어봐야 하나요?</h3>
                <p class="answer">화면 이름과 궁금한 일을 함께 적으면 정확도가 올라갑니다. 예를 들어 “대시보드 업무 바로가기 추가”, “오늘 수업 미등원 처리”, “수련비 미납 0명 이유”, “체력 리포트 사용법”처럼 물어보면 됩니다.</p>
            </article>
        </div>
    </section>
    <?php } elseif ($topic === 'faq') { ?>
    <section class="panel">
        <h2>자주하는 질문</h2>
        <div class="guide-grid">
            <article class="guide-card">
                <h3>대시보드</h3>
                <ol>
                    <li>오늘 알림은 위험도가 높은 일을 먼저 보여주는 첫 화면입니다.</li>
                    <li>카드 순서는 드래그해서 바꿀 수 있고, 톱니바퀴에서 표시할 알림을 고릅니다.</li>
                    <li>업무 바로가기는 왼쪽 메뉴의 별표로 최대 6개까지 고릅니다.</li>
                    <li>월간 스케줄과 오늘 수업은 분리해서 보고, 필요한 처리만 바로 이어갑니다.</li>
                </ol>
                <p><a href="<?php echo IEUM_URL; ?>/dashboard.php">대시보드 열기</a></p>
            </article>
            <article class="guide-card">
                <h3>원생관리</h3>
                <ol>
                    <li>전체 목록은 관리 신호 우선순위로 정렬됩니다.</li>
                    <li>왼쪽 조건에서 오늘 미등원, 장기 미등원, 메모, 연락처 누락을 골라 봅니다.</li>
                    <li>이름을 누르면 원생 프로필이 열립니다.</li>
                    <li>원생 정보 수정은 프로필 또는 수정 화면에서 진행합니다.</li>
                </ol>
                <p><a href="<?php echo IEUM_URL; ?>/admin/students.php">원생관리 열기</a></p>
            </article>
            <article class="guide-card">
                <h3>출석 처리</h3>
                <ol>
                    <li>대시보드 오늘 수업에서 부별 현황을 봅니다.</li>
                    <li>미등원은 오늘 출석 관리에서 원생별로 확인합니다.</li>
                    <li>부별 일괄 처리는 대시보드 수업 카드 하단에서 처리합니다.</li>
                    <li>알림 발송 여부를 선택해 보호자 안내 흐름을 조정합니다.</li>
                </ol>
                <p><a href="<?php echo IEUM_URL; ?>/admin/attendance_today.php">오늘 출석 관리</a></p>
            </article>
            <article class="guide-card">
                <h3>수련비/문자</h3>
                <ol>
                    <li>미납은 이번 달 미납/부분납 전체입니다.</li>
                    <li>납부 예정은 납부일이 오늘이거나 앞으로 다가오는 미납자입니다.</li>
                    <li>문자 실패는 발송현황에서 실패 상태를 확인합니다.</li>
                    <li>발송폰이 연결되어야 대기 중인 문자가 실제로 발송됩니다.</li>
                </ol>
                <p><a href="<?php echo IEUM_URL; ?>/admin/tuition_payments.php">수련비 목록</a></p>
            </article>
            <article class="guide-card">
                <h3>리포트 마감</h3>
                <ol>
                    <li>인성/체력 입력 대상과 준비 건수를 확인합니다.</li>
                    <li>연락처나 생년월일 누락 원생은 원생관리에서 보완합니다.</li>
                    <li>준비된 리포트만 인쇄 또는 링크 발송합니다.</li>
                    <li>대시보드 월말 숫자는 리포트 준비 건수입니다.</li>
                </ol>
                <p><a href="<?php echo IEUM_URL; ?>/admin/monthly_close.php">월말 마감</a></p>
            </article>
            <article class="guide-card">
                <h3>차량</h3>
                <ol>
                    <li>차량 미배정 원생은 원생관리의 차량 미배정 조건에서 확인합니다.</li>
                    <li>기사님 앱의 미탑승, 통화, 메모는 차량 메모로 모입니다.</li>
                    <li>당일 특이사항은 대시보드 알림 카드에서 바로 진입합니다.</li>
                    <li>차량 배정은 원생별 픽업/하원 설정과 연결됩니다.</li>
                </ol>
                <p><a href="<?php echo IEUM_URL; ?>/admin/vehicle_boarding.php">차량 탑승확인</a></p>
            </article>
            <article class="guide-card">
                <h3>승급/승급증</h3>
                <ol>
                    <li>승급 대상에서 이번 달 심사 준비 원생을 확인합니다.</li>
                    <li>승급 처리 후 승급증 인쇄 화면에서 인쇄 대기 원생을 봅니다.</li>
                    <li>원생을 선택해 미리보기로 이름, 띠, 급수, 발급일을 확인합니다.</li>
                    <li>인쇄 후 인쇄 완료 표시를 하면 같은 원생을 중복 출력하지 않게 관리할 수 있습니다.</li>
                </ol>
                <p><a href="<?php echo IEUM_URL; ?>/admin/promotion_certificates.php">승급증 인쇄</a></p>
            </article>
            <article class="guide-card">
                <h3>알림 담당자</h3>
                <ol>
                    <li>미등원, 차량, 시스템, 메모 알림 담당자를 나눕니다.</li>
                    <li>원생관리, 상담, 수련비, 리포트 알림도 별도 체크할 수 있습니다.</li>
                    <li>담당자 연락처는 문자 알림을 만들 때 사용됩니다.</li>
                    <li>실제 발송은 문자 발송폰이 대기 중인 문자를 보낼 때 진행됩니다.</li>
                </ol>
                <p><a href="<?php echo IEUM_URL; ?>/admin/contacts.php">알림 담당자</a></p>
            </article>
        </div>
    </section>
    <?php } elseif ($topic === 'ai') { ?>
    <section class="panel">
        <h2>AI 챗봇</h2>
        <p>원생관리, 대시보드, 출석, 수련비, 문자, 리포트, 휴관일 흐름을 물어보면 바로 답하는 운영 도우미입니다. 현재 버전은 개인정보를 외부로 보내지 않고, 아이이음 내부 도움말 기준으로 답합니다.</p>
        <div class="chat-shell" id="supportChat">
            <aside class="chat-side">
                <h3>바로 물어보기</h3>
                <div class="chat-chip-list">
                    <button type="button" class="chat-chip" data-chat-question="원생관리 신호 색깔이 뭐야?">원생관리 신호</button>
                    <button type="button" class="chat-chip" data-chat-question="장기 미등원 기준 알려줘">장기 미등원 기준</button>
                    <button type="button" class="chat-chip" data-chat-question="수련비 미납 원생만 보고 싶어">수련비 미납 보기</button>
                    <button type="button" class="chat-chip" data-chat-question="원생 프로필은 어디서 봐?">원생 프로필</button>
                    <button type="button" class="chat-chip" data-chat-question="프로필 처리 바로가기는 뭐야?">처리 바로가기</button>
                    <button type="button" class="chat-chip" data-chat-question="최근 처리 기록은 어디에 남아?">처리 기록</button>
                    <button type="button" class="chat-chip" data-chat-question="수련비 미납 문자는 어디서 이어져?">수련비 문자 흐름</button>
                    <button type="button" class="chat-chip" data-chat-question="연락처 누락은 어떻게 고쳐?">연락처 누락</button>
                    <button type="button" class="chat-chip" data-chat-question="문자 실패는 어디서 확인해?">문자 실패</button>
                    <button type="button" class="chat-chip" data-chat-question="체력리포트는 어떻게 사용해?">체력 리포트</button>
                    <button type="button" class="chat-chip" data-chat-question="인성리포트는 어떻게 사용해?">인성 리포트</button>
                    <button type="button" class="chat-chip" data-chat-question="월말 마감 숫자는 뭐야?">월말 마감</button>
                    <button type="button" class="chat-chip" data-chat-question="업무 바로가기는 어떻게 추가해?">업무 바로가기</button>
                    <button type="button" class="chat-chip" data-chat-question="오늘 알림 확인 버튼은 뭐야?">오늘 확인 버튼</button>
                    <button type="button" class="chat-chip" data-chat-question="오늘 수업 미등원은 어떻게 처리해?">오늘 수업</button>
                    <button type="button" class="chat-chip" data-chat-question="월간 스케줄 메모 문자 알림은 어떻게 써?">스케줄 메모</button>
                    <button type="button" class="chat-chip" data-chat-question="선거일 휴관은 자동으로 반영돼?">공휴일 휴관</button>
                    <button type="button" class="chat-chip" data-chat-question="승급증 인쇄 방법 알려줘">승급증 인쇄</button>
                    <button type="button" class="chat-chip" data-chat-question="생일자 문자는 어디서 보내?">생일 케어</button>
                </div>
                <p>예: “원생관리에서 장기 미등원만 보는 방법”, “수련비 미납 0명으로 보이는 이유”, “선거일인데 정상 수업으로 보이는 이유”, “원생 연락처 수정 위치”처럼 물어보세요.</p>
            </aside>
            <div class="chat-box">
                <div class="chat-log" id="chatLog" aria-live="polite"></div>
                <form class="chat-form" id="chatForm">
                    <input type="text" id="chatInput" placeholder="원생관리나 대시보드 사용법을 물어보세요." autocomplete="off">
                    <button type="submit" class="btn">질문</button>
                </form>
            </div>
        </div>
    </section>
    <?php } ?>

    <?php if ($topic === 'contact') { ?>
    <section class="panel">
        <h2>문의하기</h2>
        <form method="post" class="inquiry-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="submit_inquiry">
            <label>분류
                <select name="category">
                    <option value="contact">문의</option>
                    <option value="qna">Q&A</option>
                    <option value="faq">자주하는 질문</option>
                    <option value="ai">AI 챗봇</option>
                </select>
            </label>
            <label>연락처
                <input type="text" name="contact_phone" placeholder="010-0000-0000">
            </label>
            <label>담당자
                <input type="text" name="contact_name" value="<?php echo get_text(isset($member['mb_name']) ? $member['mb_name'] : ''); ?>" placeholder="예: 관장님">
            </label>
            <label>제목
                <input type="text" name="title" placeholder="예: 대시보드 수련비 숫자 확인 요청" required>
            </label>
            <label class="wide">내용
                <textarea name="body" placeholder="어떤 화면에서 어떤 숫자나 흐름이 이상한지 적어 주세요." required></textarea>
            </label>
            <div class="wide"><button type="submit" class="btn">문의 접수</button></div>
        </form>
    </section>

    <section class="panel">
        <h2>최근 문의</h2>
        <div class="recent-list">
            <?php $recent_count = 0; while ($row = sql_fetch_array($recent_inquiries)) { $recent_count++; ?>
            <div class="recent-row">
                <span class="badge <?php echo $row['status'] === 'open' ? 'open' : ''; ?>"><?php echo get_text($row['status'] === 'open' ? '접수' : $row['status']); ?></span>
                <strong><?php echo get_text($row['title']); ?></strong>
                <span><?php echo get_text(ieum_support_topic_label($row['category'])); ?></span>
                <span><?php echo get_text(substr((string) $row['created_at'], 0, 16)); ?></span>
            </div>
            <?php } ?>
            <?php if ($recent_count === 0) { ?><p>아직 접수된 문의가 없습니다.</p><?php } ?>
        </div>
    </section>
    <?php } ?>
</main>
<script>
(function(){
    var rootSelector = '.support-page-tune.ieum-dashboard-page';
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
<script>
(function() {
    var chatRoot = document.getElementById('supportChat');
    if (!chatRoot) {
        return;
    }

    var urls = {
        dashboard: <?php echo json_encode(IEUM_URL . '/dashboard.php'); ?>,
        dashboardSchedule: <?php echo json_encode(IEUM_URL . '/dashboard.php#calendarSchedule'); ?>,
        students: <?php echo json_encode(IEUM_URL . '/admin/students.php'); ?>,
        studentsMissing: <?php echo json_encode(IEUM_URL . '/admin/students.php?insight=missing_today'); ?>,
        studentsLongAbsent: <?php echo json_encode(IEUM_URL . '/admin/students.php?insight=long_absent&open=1'); ?>,
        studentsMemo: <?php echo json_encode(IEUM_URL . '/admin/students.php?insight=memo'); ?>,
        studentsNoGuardian: <?php echo json_encode(IEUM_URL . '/admin/students.php?insight=no_guardian'); ?>,
        tuitionUnpaid: <?php echo json_encode(IEUM_URL . '/admin/tuition_payments.php?payment_filter=unpaid#paymentList'); ?>,
        tuitionDueToday: <?php echo json_encode(IEUM_URL . '/admin/tuition_payments.php?payment_filter=due_today#paymentList'); ?>,
        tuitionDueUpcoming: <?php echo json_encode(IEUM_URL . '/admin/tuition_payments.php?payment_filter=due_upcoming#paymentList'); ?>,
        attendance: <?php echo json_encode(IEUM_URL . '/admin/attendance_today.php'); ?>,
        schoolCalendar: <?php echo json_encode(IEUM_URL . '/admin/school_calendar.php'); ?>,
        smsQueue: <?php echo json_encode(IEUM_URL . '/admin/sms_queue.php'); ?>,
        smsFailed: <?php echo json_encode(IEUM_URL . '/admin/sms_queue.php?status=failed'); ?>,
        smsDevices: <?php echo json_encode(IEUM_URL . '/admin/sms_devices.php'); ?>,
        characterInput: <?php echo json_encode(IEUM_URL . '/admin/character.php'); ?>,
        characterReport: <?php echo json_encode(IEUM_URL . '/admin/character_report.php'); ?>,
        characterMission: <?php echo json_encode(IEUM_URL . '/admin/character_mission.php'); ?>,
        fitnessInput: <?php echo json_encode(IEUM_URL . '/admin/fitness.php'); ?>,
        fitnessReport: <?php echo json_encode(IEUM_URL . '/admin/fitness_reports.php'); ?>,
        fitnessStandards: <?php echo json_encode(IEUM_URL . '/admin/fitness_standards.php'); ?>,
        promotionTargets: <?php echo json_encode(IEUM_URL . '/admin/promotion_targets.php'); ?>,
        promotionCertificates: <?php echo json_encode(IEUM_URL . '/admin/promotion_certificates.php'); ?>,
        promotionExamNotices: <?php echo json_encode(IEUM_URL . '/admin/promotion_exam_notices.php'); ?>,
        monthlyClose: <?php echo json_encode(IEUM_URL . '/admin/monthly_close.php'); ?>,
        contacts: <?php echo json_encode(IEUM_URL . '/admin/contacts.php'); ?>,
        vehicles: <?php echo json_encode(IEUM_URL . '/admin/vehicle_boarding.php'); ?>,
        contactSupport: <?php echo json_encode(IEUM_URL . '/admin/support.php?topic=contact'); ?>
    };

    var knowledge = [
        {
            keywords: ['원생관리', '원생관리', '회원관리', '원생 목록', '원생 목록', '목록', '필터', '조건', '정렬'],
            title: '원생관리 화면은 이렇게 보면 됩니다',
            answer: '원생관리 첫 화면은 전체 원생을 관리 신호 우선순위로 정렬합니다. 왼쪽 조건에서 오늘 미등원, 장기 미등원, 아이들 메모, 연락처 누락, 수련비 미납 같은 항목을 골라 볼 수 있고, 원생 이름을 누르면 원생 프로필이 열립니다. 조건을 선택하면 목록 위에 그 화면에서 바로 해야 할 다음 행동도 함께 표시됩니다.',
            links: [
                ['원생관리 열기', urls.students],
                ['아이들 메모', urls.studentsMemo]
            ]
        },
        {
            keywords: ['신호', '색깔', '뱃지', '위험', '주의', '확인', '우선순위', '관리 신호'],
            title: '원생관리 신호 색깔 기준',
            answer: '위험은 바로 처리해야 할 항목입니다. 예를 들면 장기 미등원, 수련비 미납, 리포트 발송 불가입니다. 주의는 확인이 필요한 항목이고 연락처 누락, 차량 미배정, 오늘 미등원 등이 여기에 가깝습니다. 일반 확인은 생일, 메모, 오늘 수업처럼 놓치면 아쉬운 운영 신호입니다.',
            links: [
                ['원생관리 열기', urls.students],
                ['장기 미등원', urls.studentsLongAbsent]
            ]
        },
        {
            keywords: ['장기 미등원', '장기미등원', '안부', '14일', '오래 안옴', '오래 안 온', '미등원 기준'],
            title: '장기 미등원 기준',
            answer: '장기 미등원은 최근 출석 또는 입관 기준으로 14일 이상 등원 기록이 없는 재원생입니다. 대시보드에서는 오늘 확인 처리하지 않은 항목만 우선 보여주고, 연락이나 상담 후 오늘 확인을 누르면 오늘 화면에서만 숫자에서 빠집니다. 내일도 등원 기록이 없으면 다시 표시됩니다.',
            links: [
                ['장기 미등원 목록', urls.studentsLongAbsent],
                ['오늘 출석 관리', urls.attendance]
            ]
        },
        {
            keywords: ['오늘 미등원', '미등원', '등원 전', '출석 안함', '오늘 안옴', '오늘 안 온'],
            title: '오늘 미등원 기준',
            answer: '오늘 미등원은 오늘 요일에 수업 대상인 재원생 중 오늘 출석 기록이 없는 원생입니다. 공휴일이나 도장 휴관일은 수업 대상에서 제외되므로 미등원도 0명으로 잡힙니다. 출석 처리는 오늘 출석 관리에서 이어가면 됩니다.',
            links: [
                ['오늘 미등원 목록', urls.studentsMissing],
                ['오늘 출석 관리', urls.attendance]
            ]
        },
        {
            keywords: ['휴관', '공휴일', '선거일', '대체공휴일', '수업일', '수업일설정', '휴관일', '보충수업', '보충 수업', '정상수업', '정상 수업'],
            title: '휴관일과 공휴일 수업 기준',
            answer: '공휴일과 도장 휴관일은 정상 수업일에서 제외됩니다. 그래서 대시보드 미등원, 오늘 수업, 출석 대상 숫자도 0명으로 계산됩니다. 토요 보충이나 임시 수업처럼 실제 수업을 하는 날은 수업일/휴관일에서 보충 수업으로 등록하면 됩니다.',
            links: [
                ['수업일/휴관일', urls.schoolCalendar],
                ['오늘 출석 관리', urls.attendance]
            ]
        },
        {
            keywords: ['프로필', '회원 프로필', '원생 프로필', '이름 누르면', '수정', '원생 정보', '원생 정보'],
            title: '원생 프로필 보는 방법',
            answer: '원생관리 목록에서 원생 이름을 누르면 원생 프로필이 열립니다. 프로필에는 관리 신호, 다음 케어, 처리 바로가기, 보호자 연락처, 수업, 수련비, 문자/알림 흐름, 최근 문자, 최근 처리 기록이 모여 있습니다. 정보 수정은 프로필 안의 수정 버튼으로 이어갑니다.',
            links: [
                ['원생관리 열기', urls.students]
            ]
        },
        {
            keywords: ['처리 바로가기', '프로필 바로가기', '업무 바로가기', '출석 확인', '수련비 확인', '문자 기록', '리포트 바로가기', '차량 바로가기', '원생별 처리'],
            title: '원생 프로필 처리 바로가기',
            answer: '원생 프로필의 처리 바로가기는 원생을 보다가 바로 이어야 할 일을 모아둔 영역입니다. 출석 확인은 오늘 출석/미등원 화면으로, 수련비 확인은 이번 달 납부 상태로, 문자 기록은 해당 원생 문자 검색으로 이어집니다. 리포트와 차량도 원생 정보를 보완해야 할 때 바로 이동할 수 있습니다.',
            links: [
                ['원생관리 열기', urls.students],
                ['오늘 출석 관리', urls.attendance],
                ['수련비 미납 목록', urls.tuitionUnpaid],
                ['문자 발송현황', urls.smsQueue]
            ]
        },
        {
            keywords: ['최근 처리 기록', '처리 기록', '오늘 확인 기록', '확인 처리 기록', '대시보드 확인', '누가 확인', '기록 남아'],
            title: '최근 처리 기록 기준',
            answer: '원생 프로필의 최근 처리 기록은 원생별로 남는 운영 흔적입니다. 대시보드 오늘 알림에서 확인 처리한 장기 미등원 같은 기록, 원생 상태 변경, 최근 수정 시간이 함께 표시됩니다. 오늘 확인은 오늘 대시보드에서만 숨기는 처리이고, 조건이 그대로면 다음날 다시 표시될 수 있습니다.',
            links: [
                ['원생관리 열기', urls.students],
                ['대시보드 열기', urls.dashboard]
            ]
        },
        {
            keywords: ['수련비 문자 흐름', '수련비 문자 이어서', '미납 문자 흐름', '미납 안내 흐름', '프로필 수련비', '문자 알림 흐름'],
            title: '수련비와 문자 흐름',
            answer: '원생 프로필의 문자/알림 흐름은 보호자 문자 가능 여부, 이번 달 수련비 상태, 내부 알림 담당자 상태를 함께 보여줍니다. 미납 금액이 있으면 프로필의 수련비 확인으로 납부 상태를 보고, 수련비 관리에서 안내 문자를 준비한 뒤 문자 발송현황에서 성공과 실패를 확인하면 됩니다.',
            links: [
                ['수련비 미납 목록', urls.tuitionUnpaid],
                ['문자 발송현황', urls.smsQueue],
                ['알림 담당자', urls.contacts]
            ]
        },
        {
            keywords: ['연락처', '보호자', '누락', '전화번호', '보호자 연락처', '리포트 발송 불가'],
            title: '연락처 누락 처리',
            answer: '연락처 누락은 활성 원생인데 사용 중인 보호자 연락처가 없는 상태입니다. 원생관리에서 연락처 누락 조건으로 원생을 찾고, 원생 수정 화면에서 보호자 이름과 전화번호를 보완하면 문자, 미등원 연락, 리포트 발송 흐름이 정상화됩니다.',
            links: [
                ['연락처 누락 목록', urls.studentsNoGuardian],
                ['원생관리 열기', urls.students]
            ]
        },
        {
            keywords: ['수련비', '미납', '부분납', '결제', '납부', '수련비 미납', '미납자', '미납 문자', '수련비 문자', '청구 문자', '납부 안내 문자'],
            title: '수련비 미납 확인',
            answer: '수련비 미납은 이번 달 수련비가 아직 완납되지 않은 원생입니다. 수련비 관리에서 확인 필요를 누르면 미납 원생만 볼 수 있고, 원생을 선택해 안내 문자를 보낼 수 있습니다. 미납자가 있는데 대시보드가 0명이라면 이번 달 청구가 만들어져 있는지, 납부완료로 잘못 처리되어 있지 않은지 먼저 확인하세요.',
            links: [
                ['수련비 미납 목록', urls.tuitionUnpaid],
                ['납부 예정', urls.tuitionDueUpcoming]
            ]
        },
        {
            keywords: ['수련비 안내 문자', '수련비문자보내기', '미납문자보내기', '청구문자보내기', '수련비 문자 보내는 순서', '미납 안내문자'],
            title: '수련비 안내 문자 보내는 순서',
            answer: '수련비 관리에서 이번 달을 선택한 뒤 확인 필요 또는 납부 예정으로 원생을 좁힙니다. 보낼 원생을 체크하고 문자 문구를 확인한 다음 발송을 누르세요. 발송 후에는 문자 발송현황에서 성공과 실패를 확인하면 됩니다.',
            links: [
                ['수련비 미납 목록', urls.tuitionUnpaid],
                ['문자 발송현황', urls.smsQueue]
            ]
        },
        {
            keywords: ['납부 예정', '오늘 납부일', '납부일', '오늘 확인 대상', '납부 예정일', '오늘 수련비'],
            title: '납부 예정 기준',
            answer: '납부 예정은 이번 달 미납 중 납부일이 오늘이거나 앞으로 다가오는 원생입니다. 오늘이 3일이고 납부일이 5일이면 납부 예정에 표시됩니다. 이미 납부일이 지난 원생은 수련비 미납 또는 미납 초과 기준으로 따로 확인합니다.',
            links: [
                ['납부 예정 목록', urls.tuitionDueUpcoming],
                ['수련비 미납 목록', urls.tuitionUnpaid]
            ]
        },
        {
            keywords: ['문자', '문자 실패', '발송 실패', '문자 발송', '발송폰', '문자폰'],
            title: '문자 실패와 발송폰 확인',
            answer: '문자 실패는 발송을 시도했지만 실패한 문자입니다. 실패 건은 문자 발송현황에서 확인하고, 실제 발송이 되려면 문자 발송폰이 연결되어 있어야 합니다. 자동 발송 시간도 문자 발송폰 관리에서 확인합니다.',
            links: [
                ['문자 실패 확인', urls.smsFailed],
                ['문자 발송폰 관리', urls.smsDevices]
            ]
        },
        {
            keywords: ['생일', '생일자', '생일 문자', '생일 챙기기'],
            title: '생일자 케어',
            answer: '생일 챙기기는 이번 달 또는 가까운 생일 원생을 놓치지 않기 위한 신호입니다. 원생관리에서 생일 조건으로 확인하고, 원생별 빠른 처리에서 보호자에게 보낼 문자를 만들 수 있습니다.',
            links: [
                ['원생관리 열기', urls.students],
                ['문자 발송현황', urls.smsQueue]
            ]
        },
        {
            keywords: ['메모', '아이들 메모', '상담', '상담 메모', '케어', '다음 케어'],
            title: '아이들 메모와 상담 메모',
            answer: '아이들 메모는 원생 기본 메모, 상담 메모, 승급 메모, 수련비 메모, 차량 메모처럼 운영자가 다시 봐야 할 내용을 모아보는 신호입니다. 원생 이름을 눌러 원생 프로필에서 다음 케어와 최근 처리 기록을 함께 확인하세요.',
            links: [
                ['아이들 메모 목록', urls.studentsMemo],
                ['원생관리 열기', urls.students]
            ]
        },
        {
            keywords: ['체력리포트', '체력 리포트', '체력측정', '체력 측정', '체력입력', '체력 입력', '체력은어떻게', '체력사용', '체력 사용', '체력기준표', '체력 기준표'],
            title: '체력 리포트 사용 방법',
            answer: '체력 리포트는 먼저 체력 입력에서 키, 몸무게, 유연성, 순발력 같은 측정값을 입력하고, 체력 리포트 화면에서 원생별 결과를 확인하거나 인쇄/발송하는 흐름입니다. 기준표는 체력 기준표에서 관리합니다. 월말 마감에서는 측정 완료 원생만 발송 가능 대상으로 잡힙니다.',
            links: [
                ['체력 입력', urls.fitnessInput],
                ['체력 리포트', urls.fitnessReport],
                ['체력 기준표', urls.fitnessStandards],
                ['월말 마감', urls.monthlyClose]
            ]
        },
        {
            keywords: ['인성리포트', '인성 리포트', '인성입력', '인성 입력', '인성은어떻게', '인성사용', '인성 사용', '아이잘해', '미션', '월간인성', '월간 인성'],
            title: '인성 리포트 사용 방법',
            answer: '인성 리포트는 먼저 인성 입력에서 부별 주간 인성 점수와 메모를 입력하고, 월간 인성 화면에서 원생별 리포트를 확인하거나 인쇄/발송합니다. 아이잘해 미션을 쓰면 가정 실천 내용도 함께 관리할 수 있습니다.',
            links: [
                ['인성 입력', urls.characterInput],
                ['월간 인성', urls.characterReport],
                ['아이잘해 미션', urls.characterMission],
                ['월말 마감', urls.monthlyClose]
            ]
        },
        {
            keywords: ['승급증', '승급 증', '급증', '급증인쇄', '급증인쇄방법', '증서', '증서 인쇄', '승급증 인쇄', '승급증인쇄방법', '급증 인쇄', '인쇄방법', '인쇄 방법', '출력', '발급일', '승급 완료자', '승급 처리', '활용'],
            title: '승급증 인쇄 방법',
            answer: '승급증은 승급 처리된 원생에게 바로 줄 수 있는 증서입니다. 먼저 승급 대상 화면에서 원생을 승급 처리한 뒤, 승급증 인쇄 화면에서 인쇄 대기 원생을 선택합니다. 미리보기에서 이름, 띠, 급수, 발급일을 확인하고 인쇄한 다음 인쇄 완료로 표시하면 중복 출력도 줄일 수 있습니다.',
            links: [
                ['승급 대상', urls.promotionTargets],
                ['승급증 인쇄', urls.promotionCertificates],
                ['심사 안내문', urls.promotionExamNotices]
            ]
        },
        {
            keywords: ['월말', '마감', '월말마감', '마감숫자', '마감 숫자', '리포트준비', '리포트 준비', '0/250', '250명', '250건'],
            title: '월말 마감 숫자 기준',
            answer: '월말 마감의 0/250은 원생 수가 아니라 인성 리포트 준비 건수와 체력 리포트 준비 건수를 합친 값입니다. 그래서 단위는 명이 아니라 건입니다. 생년월일이나 연락처가 빠진 원생은 리포트 발송 불가로 따로 확인합니다.',
            links: [
                ['월말 마감 열기', urls.monthlyClose],
                ['원생관리 열기', urls.students]
            ]
        },
        {
            keywords: ['차량', '탑승', '미탑승', '차량 메모', '차량 미배정', '기사님'],
            title: '차량 메모와 차량 미배정',
            answer: '차량 메모는 기사님 앱이나 탑승 확인 화면에서 미탑승, 보호자 통화, 메모가 기록될 때 확인할 수 있습니다. 차량 미배정은 차량을 써야 하는 원생인데 배정 정보가 없는 상태이므로 원생관리나 차량 메뉴에서 보완합니다.',
            links: [
                ['차량 탑승확인', urls.vehicles],
                ['원생관리 열기', urls.students]
            ]
        },
        {
            keywords: ['알림 담당자', '담당자', '내부 알림', '알림 받을 사람', '문자 알림'],
            title: '알림 담당자 설정',
            answer: '알림 담당자에서는 미등원, 차량, 시스템, 메모, 원생관리, 상담, 수련비, 리포트 알림을 받을 내부 담당자를 나눕니다. 실제 발송은 문자 발송폰이 대기 중인 문자를 보낼 때 진행됩니다.',
            links: [
                ['알림 담당자', urls.contacts],
                ['문자 발송폰 관리', urls.smsDevices]
            ]
        },
        {
            keywords: ['대시보드', '메인', '오늘 알림', '알림 설정', '카드', '첫 화면'],
            title: '대시보드 오늘 알림 설정',
            answer: '대시보드는 모든 정보를 펼치는 곳이 아니라 오늘 먼저 처리할 일을 고르는 입구입니다. 알림 설정에서 미등원, 문자 실패, 아이들 메모, 장기 미등원, 수련비 미납, 월말 마감 같은 카드를 켜고 끌 수 있습니다.',
            links: [
                ['대시보드 열기', urls.dashboard]
            ]
        },
        {
            keywords: ['오늘 확인', '확인 버튼', '완료 버튼', '알림에서 빠짐', '알림 숨김', '카드 확인', '확인 처리', '오늘알림확인'],
            title: '오늘 알림 확인 버튼 기준',
            answer: '오늘 알림 카드의 작은 확인 버튼은 실제 원생 정보나 수련비 기록을 바꾸는 버튼이 아니라, 오늘 대시보드에서만 확인한 알림을 숨기는 버튼입니다. 장기 미등원처럼 연락을 마친 항목이나 문자 실패처럼 원인을 확인한 항목에 쓰면 됩니다. 실제 조건이 해결되지 않으면 다음날 다시 표시됩니다. 수련비 미납이나 리포트 발송 불가처럼 실제 수정이 필요한 항목은 상세 화면에서 처리하세요.',
            links: [
                ['대시보드 열기', urls.dashboard],
                ['원생관리 열기', urls.students]
            ]
        },
        {
            keywords: ['업무 바로가기', '바로가기', '즐겨찾기', '별표', '별', '최대 6개', '최대6개', '왼쪽 메뉴'],
            title: '업무 바로가기 추가 방법',
            answer: '왼쪽 메뉴의 하위 메뉴 옆 별표를 누르면 대시보드 보조 정보의 업무 바로가기에 들어갑니다. 최대 6개까지 선택할 수 있고, 6개가 넘으면 안내 문구가 바로 뜹니다. 모두 해제하면 기본 바로가기가 다시 복구됩니다.',
            links: [
                ['대시보드 열기', urls.dashboard]
            ]
        },
        {
            keywords: ['오늘 수업', '수업 카드', '부별', '부 선택', '출석 처리', '일괄 처리', '미등원 전체', '알림 발송', '미발송', '수업 미등원 처리', '미등원 출석처리'],
            title: '오늘 수업 처리 방법',
            answer: '오늘 수업은 시간, 수업 부, 진행 상태, 출석, 미등원을 한 줄로 보여줍니다. 미등원이 남아 있으면 해당 수업 줄을 눌러 원생별로 확인하거나, 카드 하단에서 부를 선택하고 미등원 전체 출석 처리를 진행합니다. 보호자 안내가 필요하면 알림 발송을 선택하고, 내부 정리만 할 때는 미발송을 선택합니다.',
            links: [
                ['오늘 출석 관리', urls.attendance],
                ['대시보드 열기', urls.dashboard]
            ]
        },
        {
            keywords: ['월간 스케줄', '스케줄', '달력', '메모', '운영 메모', '문자 알림', '알림 시점', '하루 전', '2일 전', '당일', '휴관 메모', '공휴일 표시'],
            title: '월간 스케줄 메모와 문자 알림',
            answer: '월간 스케줄은 수업일 예외와 날짜별 운영 메모를 함께 보는 곳입니다. 날짜를 누르고 메모를 저장하면 달력에 짧게 표시됩니다. 문자 알림을 체크하면 당일, 하루 전, 2일 전처럼 원하는 시점에 담당자에게 운영 메모를 알려줄 수 있습니다.',
            links: [
                ['월간 스케줄 열기', urls.dashboardSchedule],
                ['알림 담당자', urls.contacts]
            ]
        },
        {
            keywords: ['챗봇', 'ai', '질문', '못 알아', '못알아', '사용법', '어떻게 물어', '어떻게사용', '알려줘', '방법'],
            title: '챗봇 질문을 정확하게 하는 방법',
            answer: '화면 이름과 하고 싶은 일을 함께 적으면 더 정확하게 답합니다. 예를 들어 “원생관리 장기 미등원 찾기”, “대시보드 업무 바로가기 추가”, “오늘 수업 미등원 처리”, “수련비 미납 0명 이유”처럼 물어보세요.',
            links: [
                ['Q&A 보기', <?php echo json_encode(IEUM_URL . '/admin/support.php?topic=qna'); ?>],
                ['문의하기', urls.contactSupport]
            ]
        }
    ];

    var log = document.getElementById('chatLog');
    var form = document.getElementById('chatForm');
    var input = document.getElementById('chatInput');

    function normalize(text) {
        return String(text || '').toLowerCase().replace(/[^0-9a-z가-힣]+/g, '');
    }

    function bestAnswer(question) {
        var normalized = normalize(question);
        var best = null;
        var bestScore = 0;
        knowledge.forEach(function(item) {
            var score = 0;
            item.keywords.forEach(function(keyword) {
                var key = normalize(keyword);
                if (key && normalized.indexOf(key) !== -1) {
                    score += key.length >= 4 ? 3 : 1;
                }
            });
            if (score > bestScore) {
                bestScore = score;
                best = item;
            }
        });
        if (best) {
            return best;
        }
        return {
            title: '조금 더 구체적으로 물어봐 주세요',
            answer: '화면 이름과 하고 싶은 일을 같이 적어 주세요. 예를 들면 “원생관리 장기 미등원 찾기”, “수련비 미납 문자 보내기”, “승급증 인쇄 방법”, “월간 스케줄 문자 알림”처럼 물어보면 바로 이어지는 메뉴와 순서를 안내합니다.',
            links: [
                ['원생관리 열기', urls.students],
                ['Q&A 보기', <?php echo json_encode(IEUM_URL . '/admin/support.php?topic=qna'); ?>],
                ['문의하기', urls.contactSupport]
            ]
        };
    }

    function appendMessage(role, payload) {
        var bubble = document.createElement('div');
        bubble.className = 'chat-msg ' + role;
        if (role === 'user') {
            var userText = document.createElement('p');
            userText.textContent = payload;
            bubble.appendChild(userText);
        } else {
            var title = document.createElement('strong');
            title.textContent = payload.title;
            var answer = document.createElement('p');
            answer.textContent = payload.answer;
            bubble.appendChild(title);
            bubble.appendChild(answer);
            if (payload.links && payload.links.length) {
                var links = document.createElement('div');
                links.className = 'chat-links';
                payload.links.forEach(function(pair) {
                    var link = document.createElement('a');
                    link.href = pair[1];
                    link.textContent = pair[0];
                    links.appendChild(link);
                });
                bubble.appendChild(links);
            }
        }
        log.appendChild(bubble);
        log.scrollTop = log.scrollHeight;
    }

    function ask(question) {
        var clean = String(question || '').trim();
        if (!clean) {
            return;
        }
        appendMessage('user', clean);
        window.setTimeout(function() {
            appendMessage('bot', bestAnswer(clean));
        }, 120);
    }

    appendMessage('bot', {
        title: '원생관리 전용 챗봇입니다',
        answer: '원생관리에서 어떤 원생을 어떻게 찾는지, 대시보드 숫자가 어떤 기준인지, 수련비/문자/리포트 흐름이 어디로 이어지는지 물어보세요.',
        links: [
            ['원생관리 열기', urls.students],
            ['Q&A 보기', <?php echo json_encode(IEUM_URL . '/admin/support.php?topic=qna'); ?>]
        ]
    });

    form.addEventListener('submit', function(event) {
        event.preventDefault();
        var question = input.value;
        input.value = '';
        ask(question);
    });

    document.querySelectorAll('[data-chat-question]').forEach(function(button) {
        button.addEventListener('click', function() {
            ask(button.getAttribute('data-chat-question'));
        });
    });
})();
</script>
</body>
</html>
