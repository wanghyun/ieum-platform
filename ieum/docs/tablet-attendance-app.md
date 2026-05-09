# 아이이음 태블릿 출석 앱 방향

## 방향 결정

태블릿 출석은 웹 키오스크가 아니라 네이티브 Android 앱을 기본 방향으로 한다.

이유:

- 출석 전용 기기는 화면 흔들림, 브라우저 주소창, 확대/축소, 스크롤이 없어야 한다.
- 아이가 직접 누르는 화면이므로 앱처럼 고정된 터치 경험이 중요하다.
- 도장별 7인치/10인치 태블릿 대응은 앱 레이아웃에서 관리하는 것이 안정적이다.
- SMS 발송 앱과 출석 태블릿 앱은 설치 기기와 권한이 다르므로 분리한다.

## 구조

```text
태블릿 출석 앱
→ POST /ieum/api/attendance/checkin.php
→ 출석 저장
→ 문자 큐 생성
→ 출석 흐름/인성 등급 반환
→ 앱 결과 화면 표시
```

## Android 모듈

- Gradle project: `ieum/android-sms-gateway`
- SMS app module: `app`
- Tablet attendance module: `tablet-attendance`
- Debug APK: `tablet-attendance/build/outputs/apk/debug/tablet-attendance-debug.apk`

## 앱 초기 설정

현재 1차 버전은 앱 화면 하단 설정 입력을 사용한다.

- 서버 주소 예: `http://192.168.0.81`
- 토큰 예: `ieum-local-gateway-token-2026`
- 기기명 예: `tablet-attendance`

운영 버전에서는 설정 화면을 별도 잠금 화면으로 분리한다.

## 다음 보강

- 도장명 자동 표시
- 설정 화면 관리자 잠금
- 7인치/10인치 가로/세로 레이아웃 별도 최적화
- 사진 원형 크롭/등급 이미지 에셋 적용
- 네트워크 끊김 시 안내 화면
- 오프라인 임시 큐 여부 검토
