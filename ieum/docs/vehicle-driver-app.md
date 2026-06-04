# 아이이음 차량기사 앱 방향

차량기사 앱은 Android와 iOS를 모두 목표로 한다. 현재 레포에는 Android 개발 환경이 먼저 준비되어 있으므로 Android 모듈을 우선 만들고, iOS는 동일 API와 화면 구조를 따라 구현한다.

## Android 모듈

- Gradle project: `ieum/android-sms-gateway`
- Module: `vehicle-driver`
- Application ID: `kr.co.ieum.vehicledriver`
- Debug APK: `vehicle-driver/build/outputs/apk/debug/vehicle-driver-debug.apk`

빌드:

```powershell
cd D:\ieum-v1\EB-4_7_10_package\ieum\android-sms-gateway
.\gradlew.bat :vehicle-driver:assembleDebug
```

## 앱 첫 흐름

```text
서버 주소 입력
도장 코드 입력
차량 노선 불러오기
노선 선택
기사님 PIN 입력
로그인
오늘 등원/하원 명단 확인
```

로그인 이후에는 `driver_token`을 저장하고, 모든 API 요청에 `Authorization: Bearer {driver_token}`을 붙인다.

## 운행 화면

앱 화면은 기사님이 운전 중에도 빠르게 누를 수 있어야 한다.

- 상단: 현재 노선명, 등원/하원 전환, 새로고침, 운행 시작, 로그아웃
- 요약: 대상, 미확인, 탑승, 미탑승
- 정류장별 학생 카드
- 학생 카드: 사진, 이름, 학년/부, 연락처, 차량 메모
- 버튼: 탑승, 미탑승, 통화, 개별
- 사진 터치: 기사님 메모 입력
- 미확인 전원 탑승 버튼

## 위치 전송

현재 Android 초안은 운행 시작 시 마지막 위치를 1회 전송한다. 실제 운영 버전에서는 아래가 추가되어야 한다.

- 운행 중 주기적 위치 전송
- 백그라운드 위치 권한 안내
- 운행 종료 시 위치 전송 중지
- 배터리 절약 주기 설정
- 관리자 관제 화면에서 1~3분 이상 위치 미수신 표시

## iOS 구현 기준

iOS 앱도 별도 기능을 만들지 않고 같은 API 계약을 그대로 사용한다.

- 도장 코드/노선/PIN 로그인
- `driver_token` Keychain 저장
- 오늘 운행 명단 조회
- 탑승 상태 저장
- 위치 권한 요청과 주기 전송
- 사진 탭 메모
- 미확인 전원 탑승

## 다음 보강

1. Android 실제 태블릿/폰에서 UI 크기 확인
2. 위치 주기 전송과 운행 종료 처리
3. 전화 버튼 추가
4. 오프라인 임시 저장 후 재전송
5. 앱 아이콘/스플래시/브랜드 컬러 적용
6. iOS 프로젝트 생성
