<?php
/**
 * skin file : /theme/THEME_NAME/skin/member/basic/admlogin.skin.html.php
 */
if (!defined('_EYOOM_')) exit;

add_stylesheet('<link rel="stylesheet" href="'.EYOOM_THEME_URL.'/plugins/perfect-scrollbar/perfect-scrollbar.min.css" type="text/css" media="screen">',0);
add_stylesheet('<link rel="stylesheet" href="'.EYOOM_THEME_URL.'/plugins/sweetalert2/sweetalert2.min.css" type="text/css" media="screen">',0);
?>

<style>
.eb-login .login-btn {text-align:center;position:relative;overflow:hidden;width:100%;padding:0}
.eb-login .login-btn .btn-e-lg {width:100%;padding:10px 0;border-radius:5px !important;font-weight:bold;font-size:1rem;background:#2d2d38}
.eb-login .login-btn .btn-e-lg:hover {background:#43434d;border:1px solid #43434d}
.login-box {position:relative;padding:30px 20px}
.login-box a:hover {text-decoration:underline}
.login-box .login-box-in {margin:0 auto;width:500px;height:auto;padding:0}
.login-box .login-box-in .login-form {padding:30px 50px;color:#171C29}
.login-box .login-box-in .login-form-margin-bottom {margin-bottom:20px}
.login-box .login-box-in .login-form h1 {font-size:30px;font-weight:700;text-align:center;margin:0 0 30px}
.login-box .login-box-in .login-form .input input::placeholder {color:#b5b5b5}
.login-box .login-box-in .login-form .input .pv-icon {position:absolute;top:8px;right:45px;cursor:pointer}
.login-box .login-box-in .login-form .input .pv-icon.is-active i {display:none}
.login-box .login-box-in .login-form .input .pv-icon.is-active:after {font-family:'Font Awesome\ 5 Free';content:"\f070";font-weight:900}
.login-input {display:flex;gap:10px;align-items:flex-end}
.login-input .input {flex:1;margin-bottom:0}
.login-input #btn_send_authkey {white-space:nowrap;padding:8px 20px;margin-bottom:0;min-width:100px}
.login-input #btn_send_authkey.btn-authkey-loading {opacity:0.7;pointer-events:none}
.login-input #btn_send_authkey.btn-authkey-loading i {display:inline-block;animation:spin-loader 1s linear infinite}
@keyframes spin-loader {from {transform:rotate(0deg)} to {transform:rotate(360deg)}}
.login-box .login-box-in .login-link {text-align:right}
.login-box .login-box-in .login-link a {font-size:.9375rem}
.login-box .login-box-in .login-link a:hover {text-decoration:underline;color:#000}
.login-box .login-box-in .login-link a:before {content:"|";margin-left:7px;margin-right:7px;color:#d5d5d5}
.login-box .login-box-in .login-link a:first-child:before {display:none}
.login-box .login-type {text-align:center;margin-top:20px}
.login-box .login-type a {font-size:.875rem;color:#777}
.login-box .login-type a:hover {text-decoration:underline;color:#000}
.login-box .login-box-in #sns_login h5 {text-align:center;color:#353535;font-size:.9375rem;margin-bottom:15px}
.login-box .login-box-in .non-members {padding:30px 50px;color:#171C29}
.login-box .login-box-in .non-members .scroll-box-login {position:relative;overflow:hidden;border:1px solid #b5b5b5;padding:10px;height:150px}
.login-box .login-box-in .non-member-order {padding:30px 50px;color:#171C29}
.eyoom-form .btn-e {box-sizing:border-box;-moz-box-sizing:border-box}
@media (max-width: 991px) {
    .login-box .login-box-in .login-form {padding:30px 50px}
    .login-box .login-box-in .non-members {padding:30px 50px}
    .login-box .login-box-in .non-member-order {padding:90px 60px}
}
@media (max-width: 767px) {
    .login-box .login-box-in {height:auto}
    .login-box .login-box-in .login-form {padding:30px 20px;margin-right:0}
    .login-box .login-box-in .login-form-margin-bottom {margin-bottom:10px}
    .login-box .login-box-in .login-form h1 {font-size:30px}
    .login-box .login-box-in .login-sidebar {display:none}
    .login-box .login-box-in .non-members {padding:30px 20px}
    .login-box .login-box-in .non-member-order {padding:40px 20px}
}
@media (max-width: 576px) {
    .login-box {width:100%}
    .login-box .login-box-in {width:100%}
}
</style>

<div class="eb-login">
    <div class="login-content">
        <div class="login-box">
            <div class="login-box-in">
                <div class="login-form">
                    <h1>관리자 로그인</h1>
                    <form name="flogin" action="<?php echo $login_action_url;?>" onsubmit="return flogin_submit(this);" method="post" class="eyoom-form">
                    <input type="hidden" name="lmode" value='<?php echo $lmode; ?>'>
                    <section>
                        <label for="mb_id" class="label">아이디</label>
                        <label class="input">
                            <i class="icon-append fas fa-user"></i>
                            <input type="text" class="form-control frm_input required" id="mb_id" name="mb_id" placeholder="ID" required size="20" maxLength="20">
                        </label>
                    </section>
                    <?php if ($lmode === 'auth') { ?>
                    <section>
                        <label for="mb_authkey" class="label m-b-0">인증키</label>
                        <div class="login-input">
                            <label class="input">
                                <i class="icon-append fas fa-lock"></i>
                                <input type="text" class="form-control frm_input required" id="mb_authkey" name="mb_authkey" placeholder="인증키입력" required size="10" maxLength="20">
                                <span class="pv-icon" data-toggle="password" data-target="#mb_password" data-class-active="is-active"><i class="fas fa-eye"></i></span>
                            </label>
                            <a href="javascript:void(0);" id="btn_send_authkey" class="btn-e btn-e-indigo btn-e-lg">인증키 발송</a>
                        </div>
                    </section>
                    <div class="login-form-margin-bottom"></div>
                    <?php } else { ?>
                    <section>
                        <label for="mb_password" class="label">비밀번호</label>
                        <label class="input">
                            <i class="icon-append fas fa-lock"></i>
                            <input type="password" class="form-control frm_input required" id="mb_password" name="mb_password" placeholder="Password" required class="frm_input required" size="20" maxLength="20">
                            <span class="pv-icon" data-toggle="password" data-target="#mb_password" data-class-active="is-active"><i class="fas fa-eye"></i></span>
                        </label>
                    </section>
                    <div class="login-form-margin-bottom"></div>
                    <?php } ?>
                    <div class="login-btn">
                        <button type="submit" value="로그인" class="btn-e btn-e-dark btn-e-lg btn-block">로그인</button>
                    </div>

                    <div class="login-type">
                    <?php if ($lmode === 'auth') { ?>
                        <a href="<?php echo G5_BBS_URL . '/admlogin.php' ?>">관리자 계정으로 로그인</a>
                    <?php } else if ($config['cf_admin_email']) { ?>
                        <a href="<?php echo G5_BBS_URL . '/admlogin.php?lm=auth' ?>">이메일 인증키로 로그인</a>
                    <?php } ?>
                    </div>

                    <div class="text-center m-t-20">
                        <a href="<?php echo G5_URL; ?>">메인으로 돌아가기</a>
                    </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo EYOOM_THEME_URL; ?>/plugins/sweetalert2/sweetalert2.min.js"></script>
<script>
jQuery.fn.center = function () {
    this.css("position","absolute");
    this.css("top", Math.max(0, (($(window).height() - $(this).outerHeight()) / 2) + $(window).scrollTop()) + "px");
    this.css("left", Math.max(0, (($(window).width() - $(this).outerWidth()) / 2) + $(window).scrollLeft()) + "px");
    return this;
}
$('.login-box').center();

document.querySelectorAll('[data-toggle="password"]').forEach(function (el) {
    el.addEventListener("click", function (e) {
        e.preventDefault();

        var target = el.dataset.target;
        document.querySelector(target).focus();

        if (document.querySelector(target).getAttribute('type') == 'password') {
            document.querySelector(target).setAttribute('type', 'text');
        } else {
            document.querySelector(target).setAttribute('type', 'password');
        }

        if (el.dataset.classActive) el.classList.toggle(el.dataset.classActive);
    });
});

function flogin_submit(f) {
    if( $( document.body ).triggerHandler( 'login_sumit', [f, 'flogin'] ) !== false ){
        return true;
    }
    return false;
}

<?php if ($lmode === 'auth') { ?>
// 인증키 발송 버튼 클릭 이벤트
$(document).on('click', '#btn_send_authkey', function(e) {
    e.preventDefault();
    var $btn = $(this);
    var mb_id = $('#mb_id').val();
    
    // 이미 발송 중이면 중복 클릭 방지
    if ($btn.prop('disabled')) {
        return false;
    }
    
    if (!mb_id) {
        alert('아이디를 입력해주세요.');
        $('#mb_id').focus();
        return false;
    }
    
    // 버튼 상태 변경: 발송중
    $btn.prop('disabled', true);
    $btn.addClass('btn-authkey-loading');
    $btn.html('<i class="fas fa-spinner"></i> 발송중');
    
    $.ajax({
        type: 'POST',
        url: '<?php echo G5_BBS_URL; ?>/ajax.admlogin.php',
        data: {
            'mb_id': mb_id
        },
        dataType: 'json',
        timeout: 30000,
        success: function(data) {
            if (data.success) {
                // 성공
                $btn.removeClass('btn-authkey-loading');
                $btn.text('다시발송');
                $btn.prop('disabled', false);
                alert(data.message);
                $('#mb_authkey').focus();
            } else {
                // 실패 - 다시 발송 가능하도록
                $btn.removeClass('btn-authkey-loading');
                $btn.text('인증키 발송');
                $btn.prop('disabled', false);
                alert(data.message);
            }
        },
        error: function() {
            // 오류 - 다시 발송 가능하도록
            $btn.removeClass('btn-authkey-loading');
            $btn.text('인증키 발송');
            $btn.prop('disabled', false);
            alert('인증키 발송 중 오류가 발생했습니다.');
        }
    });
});
<?php } ?>

<?php
$user_agent = $_SERVER['HTTP_USER_AGENT'];
$is_iphone = (strpos($user_agent, 'iPhone') !== false);
$is_ipad = (strpos($user_agent, 'iPad') !== false);

if ($is_iphone || $is_ipad) {
?>
$(document).ready(function(){
    var touchStartTimestamp = 0;
    
    $("input, textarea, select").on('touchstart', function(event) {
        zoomDisable();
        touchStartTimestamp = event.timeStamp;
    });

    $("input, textarea, select").on('touchend', function(event) {
        var touchEndTimestamp = event.timeStamp;
        if (touchEndTimestamp - touchStartTimestamp > 500) {
            setTimeout(zoomEnable, 500);
        } else {
            zoomDisable();
            setTimeout(zoomEnable, 500);
        }
    });

    function zoomDisable(){
        $('head meta[name=viewport]').remove();
        $('head').prepend('<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">');
    }

    function zoomEnable(){
        $('head meta[name=viewport]').remove();
        $('head').prepend('<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=1">');
    }
});
<?php } ?>
</script>