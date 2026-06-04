# 차량 기사 앱 Android/iOS 진행 계획

## 방향

차량 기사 앱은 Android와 iOS를 모두 목표로 하되, 서버 API는 하나로 유지한다. Android는 현재 네이티브 모듈이 있으므로 실기기 검증을 먼저 진행하고, iOS는 같은 화면 흐름과 API 계약으로 별도 프로젝트를 만든다.

## 공통 사용자 흐름

1. 서버 주소 입력 또는 QR 연결
2. 도장 코드 확인
3. 호차/노선 선택
4. 기사 PIN 로그인
5. 오늘 등원/하원 운행 선택
6. 학생 카드 확인
7. 탑승, 미탑승, 보호자 통화, 메모 처리
8. 미확인 학생 전원 탑승 처리
9. 운행 시작/종료 및 위치 전송

## 공통 API

- `GET /ieum/api/vehicle/routes.php`
- `POST /ieum/api/vehicle/login.php`
- `GET /ieum/api/vehicle/route.php`
- `POST /ieum/api/vehicle/boarding.php`
- `POST /ieum/api/vehicle/bulk_boarding.php`
- `POST /ieum/api/vehicle/run.php`
- `POST /ieum/api/vehicle/location.php`

## Android 현재 상태

- 프로젝트: `ieum/android-sms-gateway`
- 모듈: `vehicle-driver`
- 패키지명: `kr.co.ieum.vehicledriver`
- 빌드 산출물: `vehicle-driver/build/outputs/apk/debug/vehicle-driver-debug.apk`
- 2026-05-20 기준 디버그 빌드 성공
- USB 연결 기기 설치 성공

## Android 다음 작업

1. 실제 기사 화면에서 호차 선택, 운행 시작, 탑승 상태 변경 확인
2. 위치 권한 허용 후 위치 전송 로그 확인
3. 통화 버튼, 메모 팝업, 전체 탑승 버튼 동선 점검
4. 앱 아이콘/스플래시/브랜드 컬러 적용
5. 릴리즈 서명키와 AAB 빌드 준비

## iOS 착수 기준

Windows 로컬에서는 iOS 빌드를 직접 만들 수 없으므로, Mac/Xcode 환경이 준비되면 아래 방식으로 진행한다.

1. SwiftUI 프로젝트 생성
2. Bundle ID 확정: 예) `kr.co.ieum.vehicledriver`
3. 공통 API 클라이언트 작성
4. Keychain에 `driver_token` 저장
5. 위치 권한 및 백그라운드 위치 정책 검토
6. TestFlight 내부 테스트

## 운영상 주의

- 기사 앱은 학부모용 앱이 아니므로 화면은 빠르고 크고 단순해야 한다.
- 운행 중 조작을 줄이기 위해 학생 카드는 사진, 이름, 학년/부, 연락 버튼, 상태 버튼만 우선 노출한다.
- 미탑승과 통화 메모는 관리자 대시보드에서 바로 확인되어야 한다.
- 위치 기능은 배터리와 개인정보 이슈가 있으므로 운행 시작 후에만 동작하게 한다.
