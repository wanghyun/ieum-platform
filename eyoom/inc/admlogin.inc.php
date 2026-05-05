<?php
/**
 * file : /eyoom/inc/admlogin.inc.php
 */
if (!defined('_EYOOM_')) exit;

/**
 * 관리자에서 common.php 파일을 호출할 경우에만 작동
 */
if (defined('G5_IS_ADMIN')) {
    // 관리자 접근통제 기능을 사용할 경우만 작동
    if (!isset($config['cf_use_admlogin']) || !$config['cf_use_admlogin']) {
        return;
    }

    // 관리자 접근통제 아이피가 설정되어 있지 않다면 작동하지 않음
    if (!isset($config['cf_admlogin_ip']) || !$config['cf_admlogin_ip']) {
        return;
    }

    // 회원 로그인 상태가 아니라면 관리자 로그인 페이지로 이동
    if (!$is_member) {
        alert('로그인 하십시오.', G5_BBS_URL . '/admlogin.php');
    }
}