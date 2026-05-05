<?php
require_once dirname(dirname(__DIR__)) . '/common.php';
require_once dirname(__DIR__) . '/_common.php';
require_once IEUM_PATH . '/lib/security.php';
require_once IEUM_PATH . '/lib/academy.php';
require_once IEUM_PATH . '/lib/ui.php';

if (!$is_member) {
    alert('로그인이 필요합니다.', G5_BBS_URL . '/login.php?url=' . urlencode($_SERVER['REQUEST_URI']));
}
