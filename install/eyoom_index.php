<?php
    $gmnow = gmdate('D, d M Y H:i:s').' GMT';
    @header('Expires: 0');
    @header('Last-Modified: ' . $gmnow);
    @header('Cache-Control: no-store, no-cache, must-revalidate');
    @header('Cache-Control: pre-check=0, post-check=0, max-age=0');
    @header('Pragma: no-cache');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta http-equiv="Cache-Control" content="no-cache"/>
<meta http-equiv="Expires" content="0"/>
<meta http-equiv="Pragma" content="no-cache"/>
<title>오류! <?php echo G5_VERSION ?> 설치하기</title>
<link rel="stylesheet" href="./eyoom/install/setup.css">
</head>
<body>

<div id="setup_box">
	<div id="ins_bar">
		<h1>그누보드5 & 이윰빌더를 먼저 설치해주십시오.</h1>
	</div>
	
	<ul id="progressbar">
		<li class="active">초기설정</li>
		<li>라이선스 동의</li>
		<li>정보입력</li>
		<li>설치완료</li>
	</ul>
	
	<h3 class="ins_inner_title">다음 파일을 찾을 수 없습니다.</h3>
	
	<div class="ins_inner">
	    <p class="margin-bottom-20">
		    <strong><?php echo G5_DATA_DIR.'/'.G5_DBCONFIG_FILE ?></strong>
		</p>
	    <p>설치 후 다시 실행하시기 바랍니다.</p>
	</div>
	
	<div class="inner_btn">
	    <a href="<?php echo G5_URL; ?>/eyoom/install/setup.php" class="inner_abtn margin-bottom-20">설치하기</a>
	</div>
	
	<div id="ins_ft">
	    <strong>GNUBOARD5</strong>
	    <p>GPL! OPEN SOURCE GNUBOARD</p>
	</div>
</div>

</body>
</html>
<?php 
    exit;
?>