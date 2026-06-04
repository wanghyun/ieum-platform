# 매일 진행사항 백업 루틴

작성일: 2026-06-03

## 현재 자동화

- 자동화 ID: `ieum-daily-progress-backup`
- 상태: `ACTIVE`
- 실행 시간: 매일 21:00
- 작업 위치: `D:\ieum-v1\EB-4_7_10_package`
- 백업 위치: `_backups/daily_progress`
- 수동 백업 스크립트: `ieum\tools\backup_daily_progress.ps1`
- 최근 수동 백업은 `_backups/daily_progress`에서 가장 최신 날짜 폴더를 확인한다.

## 백업에 포함할 내용

- 현재 브랜치와 git status
- 최근 커밋 로그
- 전체 working-tree diff
- 새로 만든 문서와 대시보드/회원관리/지원센터 변경 요약
- 변경이 없을 때도 빈 백업 대신 상태 메모를 남겨 연속성을 유지

## 운영 메모

- C 드라이브 세션 폴더에만 의존하지 않는다.
- 복구가 필요할 때는 먼저 `_backups/daily_progress`의 날짜별 폴더와 git diff를 확인한다.
- 큰 화면 수정 전에는 수동 백업 또는 git diff 저장을 먼저 하고 시작한다.

## 수동 백업 실행

```powershell
cd D:\ieum-v1\EB-4_7_10_package\ieum
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\backup_daily_progress.ps1
```

수동 백업은 `git-status.txt`, `git-log.txt`, `working-tree.diff`, `staged.diff`, 변경 파일 복사본, 문서 복사본을 함께 남긴다.
