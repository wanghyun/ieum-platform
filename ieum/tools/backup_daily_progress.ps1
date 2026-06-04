param(
    [string]$WorkspaceRoot = "",
    [string]$BackupRoot = ""
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($WorkspaceRoot)) {
    $WorkspaceRoot = Resolve-Path (Join-Path $PSScriptRoot "..\..")
} else {
    $WorkspaceRoot = Resolve-Path $WorkspaceRoot
}

if ([string]::IsNullOrWhiteSpace($BackupRoot)) {
    $BackupRoot = Join-Path $WorkspaceRoot "_backups\daily_progress"
}

$BackupRoot = [System.IO.Path]::GetFullPath($BackupRoot)
$WorkspaceRoot = [System.IO.Path]::GetFullPath($WorkspaceRoot)

if (-not $BackupRoot.StartsWith($WorkspaceRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "BackupRoot must stay inside WorkspaceRoot."
}

$timestamp = Get-Date -Format "yyyy-MM-dd_HHmmss"
$target = Join-Path $BackupRoot $timestamp
New-Item -ItemType Directory -Path $target -Force | Out-Null

function Save-CommandOutput {
    param(
        [string]$FileName,
        [scriptblock]$Command
    )

    $path = Join-Path $target $FileName
    try {
        & $Command | Out-File -FilePath $path -Encoding UTF8
    } catch {
        "Command failed: $($_.Exception.Message)" | Out-File -FilePath $path -Encoding UTF8
    }
}

Save-CommandOutput "git-status.txt" { git -C $WorkspaceRoot status --short --branch }
Save-CommandOutput "git-log.txt" { git -C $WorkspaceRoot log --oneline -20 }
Save-CommandOutput "working-tree.diff" { git -C $WorkspaceRoot diff -- . }
Save-CommandOutput "staged.diff" { git -C $WorkspaceRoot diff --cached -- . }

$statusLines = @(git -C $WorkspaceRoot status --porcelain=v1)
$changedFileList = Join-Path $target "changed-files.txt"
$statusLines | Out-File -FilePath $changedFileList -Encoding UTF8

$changedRoot = Join-Path $target "changed_files"
New-Item -ItemType Directory -Path $changedRoot -Force | Out-Null
foreach ($line in $statusLines) {
    if ($line.Length -lt 4) {
        continue
    }

    $relative = $line.Substring(3).Trim()
    if ($relative.Contains(" -> ")) {
        $relative = ($relative -split " -> ")[-1].Trim()
    }
    $relative = $relative.Trim('"')
    if ([string]::IsNullOrWhiteSpace($relative)) {
        continue
    }

    $source = Join-Path $WorkspaceRoot $relative
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
        continue
    }

    $dest = Join-Path $changedRoot $relative
    $destDir = Split-Path -Path $dest -Parent
    New-Item -ItemType Directory -Path $destDir -Force | Out-Null
    Copy-Item -LiteralPath $source -Destination $dest -Force
}

$docsSource = Join-Path $WorkspaceRoot "ieum\docs"
if (Test-Path -LiteralPath $docsSource) {
    $docsTarget = Join-Path $target "docs"
    New-Item -ItemType Directory -Path $docsTarget -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $docsSource "*.md") -Destination $docsTarget -Force -ErrorAction SilentlyContinue
}

$branch = (git -C $WorkspaceRoot branch --show-current)
$summary = @"
# 아이이음 진행사항 백업

- 생성 시각: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")
- 작업 위치: $WorkspaceRoot
- 백업 위치: $target
- 현재 브랜치: $branch
- 변경 파일 수: $($statusLines.Count)

## 포함 항목

- git 상태: git-status.txt
- 최근 커밋: git-log.txt
- 작업 중 diff: working-tree.diff
- 스테이징 diff: staged.diff
- 변경/신규 파일 복사본: changed_files/
- 문서 백업: docs/

## 복구 메모

먼저 git-status.txt와 changed-files.txt로 범위를 확인하고, 필요한 파일은 changed_files 폴더에서 같은 상대 경로로 비교해 복원합니다.
"@

$summary | Out-File -FilePath (Join-Path $target "summary.md") -Encoding UTF8
Write-Output $target
