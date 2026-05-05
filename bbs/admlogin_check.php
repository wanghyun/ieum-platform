<?php
include_once('./_common.php');

$g5['title'] = "로그인 검사";

// 로그인 아이디
$mb_id = isset($_POST['mb_id']) ? trim($_POST['mb_id']) : '';

if (!$mb_id) {
    alert('회원아이디가 공백이면 안됩니다.');
}

$mb = get_member($mb_id);

$lmode = isset($_POST['lmode']) ? trim($_POST['lmode']) : '';
if ($lmode === 'auth') {
    // 인증키는 6자리 숫자
    $mb_authkey = isset($_POST['mb_authkey']) ? trim($_POST['mb_authkey']) : '';
    if (!$mb_authkey) {
        alert('인증키가 공백이면 안됩니다.');
    }

    if (!preg_match('/^[0-9]{6}$/', $mb_authkey)) {
        alert('인증키 형식이 올바르지 않습니다.');
    }

    if (!(isset($mb['mb_id']) && $mb['mb_id']) || $mb['mb_lost_certify'] != $eb->encrypt_aes($mb_authkey) ) {
        alert('이메일로 전송된 인증키를 정확히 입력해 주십시오.');
    }

    $sql = "update {$g5['member_table']} set mb_lost_certify = '' where mb_id = '{$mb_id}'";
    sql_query($sql);
} else {
    // 관리자 접근통제 기능이 켜져 있다면 아이피 체크
    if ($config['cf_use_admlogin'] && $config['cf_admlogin_ip']) {
        $admlogin_ip = preg_split('/\r\n|\r|\n/', $config['cf_admlogin_ip']);
        $admlogin_ip = array_map('trim', $admlogin_ip);
        $admlogin_ip = array_filter($admlogin_ip);
        if (!in_array($_SERVER['REMOTE_ADDR'], $admlogin_ip)) {
            alert('관리자 접근이 허용된 아이피가 아닙니다.', G5_URL);
        }
    } else {
        alert('관리자 접근이 허용된 아이피가 아닙니다.', G5_URL);
    }
    
    $mb_password = isset($_POST['mb_password']) ? trim($_POST['mb_password']) : '';
    if (!$mb_id || run_replace('check_empty_member_login_password', !$mb_password, $mb_id))
        alert('회원아이디나 비밀번호가 공백이면 안됩니다.');

    // 가입된 회원이 아니다. 비밀번호가 틀리다. 라는 메세지를 따로 보여주지 않는 이유는
    // 회원아이디를 입력해 보고 맞으면 또 비밀번호를 입력해보는 경우를 방지하기 위해서입니다.
    // 불법사용자의 경우 회원아이디가 틀린지, 비밀번호가 틀린지를 알기까지는 많은 시간이 소요되기 때문입니다.
    if ((! (isset($mb['mb_id']) && $mb['mb_id']) || !login_password_check($mb, $mb_password, $mb['mb_password'])) ) {
        alert('가입된 회원아이디가 아니거나 비밀번호가 틀립니다.\\n비밀번호는 대소문자를 구분합니다.');
    }
}
 
if (! (defined('SKIP_SESSION_REGENERATE_ID') && SKIP_SESSION_REGENERATE_ID)) {
    session_regenerate_id(false);
    if (function_exists('session_start_samesite')) {
        session_start_samesite();
    }
}

// 회원아이디 세션 생성
set_session('ss_mb_id', $mb['mb_id']);
// FLASH XSS 공격에 대응하기 위하여 회원의 고유키를 생성해 놓는다. 관리자에서 검사함
generate_mb_key($mb);

// 회원의 토큰키를 세션에 저장한다. /common.php 에서 해당 회원의 토큰값을 검사한다.
if(function_exists('update_auth_session_token')) update_auth_session_token($mb['mb_datetime']);

$link = G5_ADMIN_URL;

// 관리자로 로그인시 DATA 폴더의 쓰기 권한이 있는지 체크합니다. 쓰기 권한이 없으면 로그인을 못합니다.
if( is_admin($mb['mb_id']) && is_dir(G5_DATA_PATH.'/tmp/') ){
    $tmp_data_file = G5_DATA_PATH.'/tmp/tmp-write-test-'.time();
    $tmp_data_check = @fopen($tmp_data_file, 'w');
    if($tmp_data_check){
        if(! @fwrite($tmp_data_check, G5_URL)){
            $tmp_data_check = false;
        }
    }
    if (is_resource($tmp_data_check)) @fclose($tmp_data_check);
    @unlink($tmp_data_file);

    if(! $tmp_data_check){
        alert("data 폴더에 쓰기권한이 없거나 또는 웹하드 용량이 없는 경우\\n로그인을 못할수도 있으니, 용량 체크 및 쓰기 권한을 확인해 주세요.", $link);
    }
}

goto_url($link);