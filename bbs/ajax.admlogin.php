<?php
include_once('./_common.php');
include_once(G5_LIB_PATH.'/mailer.lib.php');

header('Content-Type: application/json; charset=utf-8');

$result = array(
    'success' => false,
    'message' => '오류가 발생했습니다.'
);

if (!isset($_POST['mb_id'])) {
    $result['message'] = '아이디 정보가 없습니다.';
    echo json_encode($result);
    exit;
}

$mb_id = trim($_POST['mb_id']);

if (!$mb_id) {
    $result['message'] = '아이디를 입력해주세요.';
    echo json_encode($result);
    exit;
}

// 회원 정보 조회
$mb = get_member($mb_id);

// 회원이 없거나 관리자가 아닌 경우
if (!isset($mb['mb_id'])) {
    $result['message'] = '관리자 계정이 아닙니다.';
    echo json_encode($result);
    exit;
}

// 다중관리자 체크
$manager = sql_fetch("select * from {$g5['eyoom_manager']} where mb_id = '{$mb_id}'");

if (!is_admin($mb['mb_id']) && !$manager) {
    $result['message'] = '관리자 계정이 아닙니다.';
    echo json_encode($result);
    exit;
}

// 6자리 랜덤 숫자 생성
$authkey = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

// 암호화해서 데이터베이스에 저장
$authkey_encrypted = $eb->encrypt_aes($authkey);

$sql = "update {$g5['member_table']} set mb_lost_certify = '{$authkey_encrypted}' where mb_id = '{$mb_id}'";
sql_query($sql);

// 관리자 메일로 인증키 발송
$admin_name = $config['cf_admin_email_name'] ? $config['cf_admin_email_name'] : '관리자';
$admin_email = $config['cf_admin_email'];

// 다중관리자 메일
if ($manager) {
    $receive_email = $mb['mb_email'];
} else {
    $receive_email = $admin_email;
}

if (!$receive_email) {
    $result['message'] = '관리자 이메일이 설정되지 않았습니다.';
    echo json_encode($result);
    exit;
}

$subject = '[' . $config['cf_title'] . '] 관리자 로그인 인증키 안내';

$content = '
안녕하세요.

다음은 관리자 로그인을 위한 인증키입니다.

인증키: ' . $authkey . '

본 인증키는 24시간 이내에만 유효합니다.
본 메일을 요청하지 않은 경우 보안상 문제가 있을 수 있으니 시스템 관리자에게 알려주시기 바랍니다.

감사합니다.
' . $config['cf_title'] . ' 관리팀
';

// 메일 발송
$mail_result = mailer($admin_name, $admin_email, $receive_email, $subject, $content, 0);

if ($mail_result) {
    $result['success'] = true;
    $result['message'] = '관리자 이메일로 인증키를 발송했습니다. 이메일을 확인해주세요.';
} else {
    $result['message'] = '인증키 발송 중 오류가 발생했습니다.';
}

echo json_encode($result);
?>
