$BaseDir = 'C:\work\share\development\investment\PowerShell'

$DownloadScript = Join-Path $BaseDir 'daily_market_snapshot.ps1'
$UploadScript   = Join-Path $BaseDir 'upload_daily_market_snapshot.ps1'

$LogDir = Join-Path $BaseDir 'logs'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

$Today = Get-Date -Format 'yyyyMMdd'
$LogPath = Join-Path $LogDir "run_daily_market_snapshot_all_$Today.log"

function Write-Log {
  param([string]$Message)

  $Time = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
  $Line = "[$Time] $Message"

  Write-Host $Line
  Add-Content -Path $LogPath -Value $Line -Encoding UTF8
}

Write-Log '[START] daily market snapshot all'

try {
  Write-Log '[STEP] download start'

  & powershell.exe -ExecutionPolicy Bypass -File $DownloadScript *>> $LogPath

  if ($LASTEXITCODE -ne 0) {
    throw "download failed. exit code=$LASTEXITCODE"
  }

  Write-Log '[STEP] download finished'
  Write-Log '[STEP] upload start'

  & powershell.exe -ExecutionPolicy Bypass -File $UploadScript $Today *>> $LogPath

  if ($LASTEXITCODE -ne 0) {
    throw "upload failed. exit code=$LASTEXITCODE"
  }

  Write-Log '[STEP] upload finished'
  Write-Log '[DONE] all finished'

  exit 0

} catch {
  Write-Log "[ERROR] $_"
  exit 1
}