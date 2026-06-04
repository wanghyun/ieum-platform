# 아이이음 차량 기사 앱 API

차량 기사 앱은 Android와 iOS를 모두 목표로 한다. 웹 관리자 세션을 사용하지 않고 `driver_token` 기반으로 인증한다.

## 공통 응답

```json
{
  "ok": true,
  "message": "처리 메시지",
  "data": {}
}
```

앱 요청은 `application/json` 또는 일반 `POST form` 둘 다 허용한다. 로그인 이후 요청은 아래 헤더를 권장한다.

```http
Authorization: Bearer {driver_token}
```

## 1. 도장 차량 노선 조회

`GET /ieum/api/vehicle/routes.php?academy_code=IEUMTKD001`

기사 PIN이 설정된 활성 노선만 내려준다.

## 2. 기사 로그인

`POST /ieum/api/vehicle/login.php`

```json
{
  "academy_code": "IEUMTKD001",
  "route_id": 1,
  "driver_pin": "110022"
}
```

응답의 `driver_token`은 기본 30일 동안 유효하다.

## 3. 오늘 운행 명단

`GET /ieum/api/vehicle/route.php?journal_date=2026-05-15&ride_type=pickup`

`ride_type`은 `pickup` 또는 `dropoff`를 사용한다. 정류장 단위로 학생이 묶여 내려오며, 앱은 이 데이터를 카드 UI로 렌더링한다.

학생 상태 값:

- `unchecked`: 미확인
- `boarded`: 탑승
- `missed`: 미탑승
- `called`: 보호자 통화
- `self`: 개별 이동

## 4. 운행 시작/종료

`POST /ieum/api/vehicle/run.php`

```json
{
  "action": "start",
  "journal_date": "2026-05-15",
  "ride_type": "pickup"
}
```

`action`은 `start`, `end` 중 하나다.

## 5. 학생 탑승 상태 저장

`POST /ieum/api/vehicle/boarding.php`

```json
{
  "journal_date": "2026-05-15",
  "student_vehicle_id": 12,
  "status": "boarded",
  "note": ""
}
```

`missed`, `called` 상태는 알림 담당자 문자 큐와 연결된다.

## 6. 미확인 전원 탑승 처리

`POST /ieum/api/vehicle/bulk_boarding.php`

```json
{
  "journal_date": "2026-05-15",
  "ride_type": "pickup"
}
```

현재 노선의 미확인 학생만 탑승 처리한다.

## 7. 차량 위치 전송

`POST /ieum/api/vehicle/location.php`

```json
{
  "journal_date": "2026-05-15",
  "ride_type": "pickup",
  "lat": 37.5665,
  "lng": 126.978,
  "accuracy": 15
}
```

운행이 시작되지 않은 상태에서 위치가 들어오면 active 운행을 자동 생성한다.

## 앱 화면 우선순위

1. 도장 코드 입력 또는 QR 연결
2. 노선 선택과 기사 PIN 로그인
3. 오늘 등원/하원 명단 카드
4. 탑승, 미탑승, 통화, 개별 이동 버튼
5. 사진 탭 메모 입력
6. 미확인 전원 탑승
7. 위치 권한 안내와 백그라운드 위치 전송
