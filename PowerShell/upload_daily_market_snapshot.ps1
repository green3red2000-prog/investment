param(
  [string]$TargetDate = ''
)

# ------------------------------------------------------------------
# Target date (yyyyMMdd).
#
# Examples:
#   .\upload_daily_market_snapshot.ps1
#     -> use today's date
#
#   .\upload_daily_market_snapshot.ps1 20260615
#     -> use specified date
# ------------------------------------------------------------------

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

$RemoteCompleteFile = '/opt/invest/scraping/data/complete_upload_daily_market_snapshot.txt'
$LocalCompleteFile = Join-Path $env:TEMP "complete_upload_daily_market_snapshot.txt"

if (-not (Test-Path $LocalDir)) {
  throw "local dir not found: $LocalDir"
}

$ScriptPath = Join-Path $env:TEMP "winscp_upload_daily_market_snapshot_$Today.txt"
$LogPath = Join-Path $env:TEMP "winscp_upload_daily_market_snapshot_$Today.log"

$Lines = @(
  'option batch abort',
  'option confirm off',
  'open sftp://invest_upload@133.18.243.68/ -hostkey="ssh-ed25519 255 kwRNshQrTFTUH5++xLJL8i2WUPILoam0f/1FcaREEFI" -privatekey="C:\work\share\development\investment\ppk\invest_upload.ppk"',
  "mkdir `"$RemoteDir`"",
  "put `"$LocalDir\*.html`" `"$RemoteDir/`"",
  "put `"$LocalCompleteFile`" `"$RemoteCompleteFile`"",
  'exit'
)

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

Write-Host '[OK] upload finished'