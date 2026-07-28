$BaseDir = 'C:\work\share\development\investment\PowerShell'

$DownloadScript = Join-Path $BaseDir 'daily_market_snapshot.ps1'
$UploadScript   = Join-Path $BaseDir 'upload_daily_market_snapshot.ps1'

$LogDir = Join-Path $BaseDir 'logs'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

$TaskTracePath = Join-Path $LogDir "task_trace.log"

Add-Content -Path $TaskTracePath -Value "$(Get-Date) START" -Encoding UTF8

$Today = Get-Date -Format 'yyyyMMdd'
$LogPath = Join-Path $LogDir "run_daily_market_snapshot_all_$Today.log"

$ExitCode = 0

function Write-Log {
  param([string]$Message)

  $Time = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
  $Line = "[$Time] $Message"

  Write-Host $Line
  Add-Content -Path $LogPath -Value $Line -Encoding UTF8
}

Write-Log '[START] daily market snapshot all'

try {
  Write-Log '[STEP 1/4] index scraping start. mode=000'
 
  & powershell.exe `
    -ExecutionPolicy Bypass `
    -File $DownloadScript `
    000 `
    *>> $LogPath
 
  if ($LASTEXITCODE -ne 0) {
    throw "index scraping failed. mode=000 exit code=$LASTEXITCODE"
  }
 
  Write-Log '[STEP 1/4] index scraping finished. mode=000'
  Write-Log '[STEP 2/4] index upload start. mode=000'
 
  & powershell.exe `
    -ExecutionPolicy Bypass `
    -File $UploadScript `
    000 `
    $Today `
    *>> $LogPath
 
  if ($LASTEXITCODE -ne 0) {
    throw "index upload failed. mode=000 exit code=$LASTEXITCODE"
   }
 
  Write-Log '[STEP 2/4] index upload finished. mode=000'
  Write-Log '[STEP 3/4] other scraping start. mode=001'

  & powershell.exe `
    -ExecutionPolicy Bypass `
    -File $DownloadScript `
    001 `
    *>> $LogPath

  if ($LASTEXITCODE -ne 0) {
    throw "other scraping failed. mode=001 exit code=$LASTEXITCODE"
  }

  Write-Log '[STEP 3/4] other scraping finished. mode=001'
  Write-Log '[STEP 4/4] other upload start. mode=001'

  & powershell.exe `
    -ExecutionPolicy Bypass `
    -File $UploadScript `
    001 `
    $Today `
    *>> $LogPath

  if ($LASTEXITCODE -ne 0) {
    throw "other upload failed. mode=001 exit code=$LASTEXITCODE"
  }

  Write-Log '[STEP 4/4] other upload finished. mode=001'
  Write-Log '[DONE] all finished'

} catch {
  Write-Log "[ERROR] $_"
  $ExitCode = 1
  
} finally {
  Add-Content -Path $TaskTracePath -Value "$(Get-Date) END exit=$ExitCode" -Encoding UTF8
}

exit $ExitCode
