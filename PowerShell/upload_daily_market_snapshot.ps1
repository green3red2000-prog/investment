param(
  [Parameter(Mandatory = $true, Position = 0)]
  [ValidateSet('000', '001')]
  [string]$Mode,

  [Parameter(Position = 1)]
  [string]$TargetDate = ''
)

 # Examples:
 #   .\upload_daily_market_snapshot.ps1 000
 #     -> upload 07_ files for today's date
 #
 #   .\upload_daily_market_snapshot.ps1 001
 #     -> upload 01_ through 06_ files for today's date
 #
 #   .\upload_daily_market_snapshot.ps1 000 20260615
 #     -> upload 07_ files for specified date
 #
 #   .\upload_daily_market_snapshot.ps1 001 20260615
 #     -> upload 01_ through 06_ files for specified date

if ([string]::IsNullOrWhiteSpace($TargetDate)) {
  $TargetDate = Get-Date -Format 'yyyyMMdd'
}

$Today = $TargetDate

$WinScp = 'C:\Program Files (x86)\WinSCP\WinSCP.com'

if (-not (Test-Path $WinScp)) {
  $WinScp = 'C:\Program Files\WinSCP\WinSCP.com'
}

if (-not (Test-Path $WinScp)) {
  throw 'WinSCP.com not found'
}

$LocalRoot = 'C:\work\share\development\investment\data\kabutan\daily_market_snapshot'
$LocalDir = Join-Path $LocalRoot $Today

$RemoteRoot = '/opt/invest/scraping/data'
$RemoteDir = "$RemoteRoot/$Today"

if ($Mode -eq '000') {
  $UploadPatterns = @(
    '07_*.html'
  )
} else {
  $UploadPatterns = @(
    '01_*.html',
    '02_*.html',
    '03_*.html',
    '04_*.html',
    '05_*.html',
    '06_*.html'
  )
}


$RemoteCompleteFile = "/opt/invest/scraping/state/upload/complete_upload_daily_market_snapshot_${Today}_${Mode}.txt"
$LocalCompleteFile = Join-Path $env:TEMP "complete_upload_daily_market_snapshot_${Today}_${Mode}.txt"
if (-not (Test-Path $LocalDir)) {
  throw "local dir not found: $LocalDir"
}

$UploadFiles = @()

foreach ($Pattern in $UploadPatterns) {
  $UploadFiles += @(
    Get-ChildItem `
      -Path (Join-Path $LocalDir $Pattern) `
      -File `
      -ErrorAction SilentlyContinue
  )
}

if ($UploadFiles.Count -eq 0) {
  throw "upload target file not found. mode=$Mode localDir=$LocalDir"
}

Write-Host "[INFO] mode        : $Mode"
Write-Host "[INFO] upload files: $($UploadFiles.Count)"

$ScriptPath = Join-Path $env:TEMP "winscp_upload_daily_market_snapshot_${Today}_${Mode}.txt"
$LogPath = Join-Path $env:TEMP "winscp_upload_daily_market_snapshot_${Today}_${Mode}.log"

$Lines = @(
  'option batch abort',
  'option confirm off',
  'open sftp://invest_upload@133.18.243.68/ -hostkey="ssh-ed25519 255 kwRNshQrTFTUH5++xLJL8i2WUPILoam0f/1FcaREEFI" -privatekey="C:\work\share\development\investment\ppk\invest_upload.ppk"',
  'option batch continue',
  "mkdir `"$RemoteDir`"",
  'option batch abort'
)

foreach ($UploadFile in $UploadFiles) {
  $Lines += "put `"$($UploadFile.FullName)`" `"$RemoteDir/`""
}

$Lines += "put `"$LocalCompleteFile`" `"$RemoteCompleteFile`""
$Lines += 'exit'

$CompleteDate = [datetime]::ParseExact($Today,'yyyyMMdd',$null).ToString('yyyy-MM-dd')
[System.IO.File]::WriteAllText($LocalCompleteFile,$CompleteDate,[System.Text.Encoding]::ASCII)

[System.IO.File]::WriteAllLines($ScriptPath, $Lines, [System.Text.Encoding]::ASCII)

Write-Host "[INFO] local : $LocalDir"
Write-Host "[INFO] remote: $RemoteDir"
Write-Host "[INFO] script: $ScriptPath"
Write-Host "[INFO] log   : $LogPath"

& $WinScp /script="$ScriptPath" /log="$LogPath"

if ($LASTEXITCODE -ne 0) {
  Write-Host "[ERROR] WinSCP failed. exit code = $LASTEXITCODE"
  Write-Host "[ERROR] log: $LogPath"
  throw 'WinSCP upload failed'
}

Remove-Item $LocalCompleteFile -Force -ErrorAction SilentlyContinue
Remove-Item $ScriptPath -Force -ErrorAction SilentlyContinue

Write-Host "[OK] upload finished. mode=$Mode files=$($UploadFiles.Count)"