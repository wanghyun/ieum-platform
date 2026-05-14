<?php
require_once './_common.php';
require_once IEUM_PATH . '/lib/maps.php';

header('Content-Type: application/json; charset=utf-8');

$academy = ieum_require_academy_page();
$query = isset($_GET['query']) ? trim($_GET['query']) : '';
$settings = ieum_map_get_system_settings();

if ($query === '') {
    echo json_encode(array('success' => false, 'message' => '검색할 주소나 장소명을 입력하세요.'), JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($settings['use_geocoding']) || !ieum_map_has_api_key($settings) || !ieum_map_has_secret($settings)) {
    echo json_encode(array('success' => false, 'message' => '본사 지도 API 설정이 필요합니다.'), JSON_UNESCAPED_UNICODE);
    exit;
}

$url = 'https://naveropenapi.apigw.ntruss.com/map-geocode/v2/geocode?query=' . rawurlencode($query);
$headers = array(
    'X-NCP-APIGW-API-KEY-ID: ' . $settings['naver_client_id'],
    'X-NCP-APIGW-API-KEY: ' . $settings['naver_client_secret'],
    'Accept: application/json',
);

$body = false;
$status_code = 0;
if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $body = curl_exec($ch);
    $status_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
} else {
    $context = stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 8,
        ),
    ));
    $body = @file_get_contents($url, false, $context);
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
                $status_code = (int) $m[1];
                break;
            }
        }
    }
}

if ($body === false || $status_code >= 400) {
    $message = '주소 검색 API 호출에 실패했습니다.';
    $error_data = json_decode((string) $body, true);
    if ($status_code === 401 || $status_code === 403) {
        $message = '네이버 지도 API 구독 또는 권한 설정을 확인해 주세요.';
    } elseif (is_array($error_data) && !empty($error_data['error']['message'])) {
        $message = '주소 검색 API 오류: ' . $error_data['error']['message'];
    }
    echo json_encode(array('success' => false, 'message' => $message), JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($body, true);
if (!is_array($data) || empty($data['addresses'][0])) {
    echo json_encode(array('success' => false, 'message' => '검색 결과가 없습니다.'), JSON_UNESCAPED_UNICODE);
    exit;
}

$item = $data['addresses'][0];
$road_address = isset($item['roadAddress']) ? $item['roadAddress'] : '';
$jibun_address = isset($item['jibunAddress']) ? $item['jibunAddress'] : '';
$address = $road_address !== '' ? $road_address : $jibun_address;
$lng = isset($item['x']) ? $item['x'] : '';
$lat = isset($item['y']) ? $item['y'] : '';

echo json_encode(array(
    'success' => true,
    'address' => $address,
    'roadAddress' => $road_address,
    'jibunAddress' => $jibun_address,
    'lat' => $lat,
    'lng' => $lng,
    'mapUrl' => $address !== '' ? 'https://map.naver.com/v5/search/' . rawurlencode($address) : '',
), JSON_UNESCAPED_UNICODE);
