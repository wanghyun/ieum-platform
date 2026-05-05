<?php
/**
 * PHP 8.0+ 호환 number_format 래퍼 함수
 * 
 * PHP 8.0 이상에서 number_format()에 null을 전달하면 Deprecated 경고 발생
 * 이 파일은 null 값을 안전하게 처리하는 래퍼 함수를 제공합니다.
 */

if (!defined('_GNUBOARD_')) exit;

/**
 * null-safe number_format 함수
 * 
 * @param mixed $num 포맷할 숫자 (null일 경우 0으로 처리)
 * @param int $decimals 소수점 자릿수 (기본값: 0)
 * @param string $decimal_separator 소수점 구분자 (기본값: '.')
 * @param string $thousands_separator 천단위 구분자 (기본값: ',')
 * @return string 포맷된 숫자 문자열
 */
function safe_number_format($num, int $decimals = 0, string $decimal_separator = '.', string $thousands_separator = ','): string
{
    // null이거나 빈 문자열이면 0으로 처리
    if ($num === null || $num === '') {
        $num = 0;
    }
    
    // 숫자로 변환 가능한지 확인
    if (!is_numeric($num)) {
        $num = 0;
    }
    
    // float로 캐스팅하여 number_format 호출
    return number_format((float)$num, $decimals, $decimal_separator, $thousands_separator);
}

/**
 * 포인트를 포맷하여 반환 (null-safe)
 * 
 * @param mixed $point 포인트 값
 * @return string 포맷된 포인트 문자열
 */
function format_point($point): string
{
    return safe_number_format($point, 0);
}

/**
 * 가격을 포맷하여 반환 (null-safe)
 * 
 * @param mixed $price 가격 값
 * @return string 포맷된 가격 문자열
 */
function format_price($price): string
{
    return safe_number_format($price, 0);
}

/**
 * 수량을 포맷하여 반환 (null-safe)
 * 
 * @param mixed $qty 수량 값
 * @return string 포맷된 수량 문자열
 */
function format_qty($qty): string
{
    return safe_number_format($qty, 0);
}

/**
 * 파일 크기를 사람이 읽기 쉬운 형식으로 포맷 (null-safe)
 * 
 * @param mixed $size 바이트 단위 파일 크기
 * @return string 포맷된 파일 크기 문자열
 */
function format_filesize($size): string
{
    // null이거나 빈 값이면 0으로 처리
    if ($size === null || $size === '') {
        $size = 0;
    }
    
    $size = (float)$size;
    
    if ($size >= 1048576) {
        return safe_number_format($size/1048576, 1) . "M";
    } else if ($size >= 1024) {
        return safe_number_format($size/1024, 1) . "K";
    } else {
        return safe_number_format($size, 0) . "byte";
    }
}

/**
 * 비율을 포맷하여 반환 (null-safe)
 * 
 * @param mixed $rate 비율 값
 * @param int $decimals 소수점 자릿수 (기본값: 1)
 * @return string 포맷된 비율 문자열
 */
function format_rate($rate, int $decimals = 1): string
{
    return safe_number_format($rate, $decimals);
}

/**
 * 카운트 값을 포맷하여 반환 (null-safe)
 * 
 * @param mixed $count 카운트 값
 * @return string 포맷된 카운트 문자열
 */
function format_count($count): string
{
    return safe_number_format($count, 0);
}
