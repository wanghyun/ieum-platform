<?php
/**
 * core file : /eyoom/core/shop/personalpayform.sub.php
 */
if (!defined('_EYOOM_')) exit;

/**
 * 결재수단 초기설정
 */
$multi_settle = 0;
$checked = '';

$escrow_title = "";
$escrow_products = array(); // 토스페이먼츠 escrowProducts 배열 생성
if ($default['de_escrow_use']) {
    $escrow_title = "에스크로<br>";

    // 토스페이먼츠 escrowProducts 배열에 상품 정보 추가
    $escrow_products[] = array(
        'id'        => $pp['pp_id'],
        'name'      => $pp['pp_name'].'님 개인결제',
        'code'      => $pp['pp_id'],
        'unitPrice' => (int) $pp['pp_price'],
        'quantity'  => (int) 1
    );  
}

/**
 * 이윰 테마파일 출력
 */
include_once(EYOOM_THEME_SHOP_SKIN_PATH.'/personalpayform.sub.skin.html.php');