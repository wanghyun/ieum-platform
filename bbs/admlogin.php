<?php
include_once('./_common.php');

define('IS_ADMIN_LOGIN', true);

$g5['title'] = '관리자 로그인';
include_once('./_head.sub.php');

// url 체크
check_url_host($url);

// 이미 로그인 중이라면
if ($is_member) {
    if ($url)
        goto_url($url);
    else
        goto_url(G5_URL);
}

$lmode = isset($_GET['lm']) ? $_GET['lm'] : '';
$lmode = $lmode === 'auth' ? 'auth' : '';

$login_url        = login_url($url);
$login_action_url = G5_HTTPS_BBS_URL."/admlogin_check.php";
include_once($eyoom_skin_path['member'].'/admlogin.skin.html.php');

include_once('./_tail.sub.php');