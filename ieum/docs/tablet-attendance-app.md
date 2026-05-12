# 아이이음 출석기 앱 방향

## 방향 결정

태블릿 출석은 웹 키오스크를 보조 수단으로 두고, 운영 기본값은 Android 전용 앱으로 진행한다.

이유:

- 아이들이 직접 누르는 화면은 주소창, 확대/축소, 스크롤, 회전 흔들림이 없어야 한다.
- 7인치/10인치 태블릿의 가로/세로 화면을 앱에서 고정적으로 관리하는 편이 안정적이다.
- 출석 전용 기기와 문자 게이트웨이 기기는 권한과 역할이 다르므로 분리한다.
- 플레이스토어에 하나의 앱을 올리고, 각 도장은 연결 코드로 자기 도장에 묶어 사용한다.

## 운영 연결 흐름

```text
도장 관리자 페이지
→ 출석기 관리
→ 6자리 연결 코드 생성
→ 태블릿 앱에서 서버 주소 + 연결 코드 입력
→ 기기 토큰 발급
→ 해당 도장 전용 출석기로 동작
```

앱에는 관리자 아이디/비밀번호를 저장하지 않는다. 한 번 연결되면 앱은 발급받은 기기 토큰으로만 출석 API를 호출한다.

## API 구조

```text
POST /ieum/api/tablet/register.php
  - pairing_code
  - device_uid
  - device_name
  → device_token 발급

POST /ieum/api/attendance/checkin.php
  - token(device_token)
  - device
  - student_code
  - student_id(중복 번호 선택 시)
  → 출석 저장, 문자 큐 생성, 출석 흐름/인성 등급 반환
```

## Android 모듈

- Gradle project: `ieum/android-sms-gateway`
- SMS app module: `app`
- Tablet attendance module: `tablet-attendance`
- Debug APK: `tablet-attendance/build/outputs/apk/debug/tablet-attendance-debug.apk`

## 현재 앱 설정

설정은 아이들이 만지는 출석 화면에서 숨긴다.

- 최초 연결 전: 결과 영역에 `관리자 설정` 버튼 표시
- 연결 후: 도장명 제목을 길게 누르면 관리자 PIN 입력
- 기본 PIN: `110022`
- 설정 항목: 서버 주소, 연결 코드, 기기 이름, 관리자 PIN 변경

관리자 설정에서 입력하는 값:

- 서버 주소: 예 `http://192.168.0.81`
- 연결 코드: 관리자 화면에서 생성한 6자리 코드
- 기기 이름: 예 `입구 태블릿`, `2층 태블릿`

## 도장 운영 잠금 기준

앱 내부에서는 아이들이 실수로 빠져나가기 어렵게 아래 동작을 기본으로 둔다.

- 앱 실행 중 화면이 꺼지지 않도록 유지
- 포커스 복귀, 회전, 시스템바 노출 후 전체화면 자동 복귀
- 뒤로가기 입력 방어
  - 번호 입력 중이면 입력값만 초기화
  - 결과/안내 화면이면 대기 화면으로 복귀
  - 대기 화면에서는 앱 종료 방지
- 연결된 출석기는 도장명을 길게 누르고 관리자 PIN을 입력해야 설정 화면 접근

단, 일반 Play Store 앱은 Android 보안 정책상 홈 버튼 자체를 완전히 막을 수 없다. 실제 도장 운영 태블릿은 Android 설정의 `화면 고정` 또는 MDM/키오스크 모드를 함께 적용하는 것을 기본 권장값으로 둔다.

권장 운영 순서:

```text
태블릿 설정
→ 보안/개인정보 또는 앱 고정 메뉴
→ 화면 고정 사용
→ 아이이음출석기 실행
→ 최근 앱 화면에서 아이이음출석기 고정
```

도장 납품용 태블릿을 본사에서 세팅할 경우에는 MDM 또는 Android Enterprise Device Owner 방식으로 완전 키오스크 모드를 검토한다.

## 화면 대응 기준

앱은 기기를 세 등급으로 나눠 비율만 조정한다.

- 휴대폰형: `smallestScreenWidthDp < 600`
  - 관리자 테스트/임시 출석용
  - 버튼과 결과 글자를 작게, 결과 영역은 compact
- 7인치 태블릿형: `600 이상`
  - 실제 출석기 기본
  - 가로 화면은 좌측 번호판 56%, 우측 결과 44%
  - 세로 화면은 번호판 68%, 결과 32%
- 10~12인치 태블릿형: `840 이상`
  - 넓은 화면에서 좌우 여백만 커지지 않게 번호판 52%, 결과 48%
  - 사진과 등급 영역을 크게 표시

## Play Store 준비

- 앱 이름: `아이이음출석기`
- applicationId: `kr.co.ieum.attendance`
- versionCode: `1`
- versionName: `1.0.0`
- 릴리즈 AAB: `tablet-attendance/build/outputs/bundle/release/tablet-attendance-release.aab`

릴리즈 서명은 환경변수로 연결한다.

```powershell
$env:IEUM_ATTENDANCE_STORE_FILE="D:\secure\ieum-attendance-upload.jks"
$env:IEUM_ATTENDANCE_STORE_PASSWORD="..."
$env:IEUM_ATTENDANCE_KEY_ALIAS="ieum-attendance"
$env:IEUM_ATTENDANCE_KEY_PASSWORD="..."
.\gradlew.bat :tablet-attendance:bundleRelease
```

## 다음 보강

- 실제 7인치/10~12인치 태블릿과 갤럭시 S20~S26급 휴대폰 실기기 캡처 검증
- 오프라인 상태 안내와 재시도 큐
- 기기별 마지막 사용 시간과 해제/재연결 정책
- 납품용 태블릿 MDM/Device Owner 키오스크 모드 검토
