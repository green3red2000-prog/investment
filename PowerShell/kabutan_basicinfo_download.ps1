# Debug mode: 1 = local check only, 0 = normal download
$TestMode = 0
$TestHtmlPath = 'C:\work\share\development\investment\data\kabutan\basicinfo\20260614\01_basicinfo_7203_kabutan_basicinfo.html'

$Edge = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
$Port = 9222
$Profile = 'C:\work\share\development\investment\browser-profile\edge-kabutan'

$MasterCsvPath = 'C:\work\share\development\investment\data\master\security-codes.csv'
$SaveRoot = 'C:\work\share\development\investment\data\kabutan\basicinfo'

$CdpTimeoutSec = 60

Write-Host "[DEBUG] script path = $PSCommandPath"
Write-Host "[DEBUG] TestMode = $TestMode"

function Get-SecurityCodesFromMaster {
  param([string]$Path)

  if (-not (Test-Path $Path)) {
    throw "master csv not found: $Path"
  }

  Add-Type -AssemblyName Microsoft.VisualBasic

  $Codes = New-Object System.Collections.Generic.List[string]
  $Seen = @{}

  $Parser = [Microsoft.VisualBasic.FileIO.TextFieldParser]::new(
    $Path,
    [System.Text.Encoding]::Default
  )

  try {
    $Parser.TextFieldType = [Microsoft.VisualBasic.FileIO.FieldType]::Delimited
    $Parser.SetDelimiters(',')
    $Parser.HasFieldsEnclosedInQuotes = $true

    while (-not $Parser.EndOfData) {
      $Fields = $Parser.ReadFields()

      if ($Fields -eq $null -or $Fields.Count -lt 2) {
        continue
      }

      $Code = $Fields[1].Trim()

      # Header rows or invalid rows are ignored.
      # Supports numeric codes and alphabetic codes such as 262A / 290A.
      if ($Code -notmatch '^[0-9A-Z]{4}$') {
        continue
      }

      if (-not $Seen.ContainsKey($Code)) {
        $Seen[$Code] = $true
        $Codes.Add($Code) | Out-Null
      }
    }
  } finally {
    $Parser.Close()
  }

  if ($Codes.Count -lt 1) {
    throw "security code not found in master csv: $Path"
  }

  Write-Host "[INFO] security codes loaded: $($Codes.Count)"
  return @($Codes)
}

function New-BasicInfoItems {
  param([string[]]$Codes)

  $Items = @()

  foreach ($Code in $Codes) {
    $Items += @{
      Url = "https://kabutan.jp/stock/?code=$Code"
      File = "01_basicinfo_${Code}_kabutan_basicinfo.html"
      Check = 0
      Code = $Code
    }
  }

  return $Items
}

function Wait-Cdp {
  param($Port)

  for ($i = 0; $i -lt 30; $i++) {
    try {
      Invoke-RestMethod "http://127.0.0.1:$Port/json/version" | Out-Null
      return
    } catch {
      Start-Sleep -Seconds 1
    }
  }

  throw 'CDP not available'
}

function Send-Cdp {
  param($Ws, $Id, $Method, $Params)

  $Obj = @{
    id = $Id
    method = $Method
  }

  if ($Params -ne $null) {
    $Obj.params = $Params
  }

  $Json = $Obj | ConvertTo-Json -Depth 20 -Compress
  $Bytes = [System.Text.Encoding]::UTF8.GetBytes($Json)
  $Seg = New-Object System.ArraySegment[byte] -ArgumentList @(,$Bytes)

  $Cts = New-Object System.Threading.CancellationTokenSource
  $Cts.CancelAfter($CdpTimeoutSec * 1000)

  try {
    $Task = $Ws.SendAsync(
      $Seg,
      [System.Net.WebSockets.WebSocketMessageType]::Text,
      $true,
      $Cts.Token
    )

    if (-not $Task.Wait($CdpTimeoutSec * 1000)) {
      throw "CDP send timeout: id=$Id method=$Method"
    }
  } finally {
    $Cts.Dispose()
  }
}

function Receive-Cdp {
  param($Ws)

  $Buffer = New-Object byte[] 1048576
  $Ms = New-Object System.IO.MemoryStream

  $Cts = New-Object System.Threading.CancellationTokenSource
  $Cts.CancelAfter($CdpTimeoutSec * 1000)

  try {
    do {
      $Seg = New-Object System.ArraySegment[byte] -ArgumentList @(,$Buffer)
      $Task = $Ws.ReceiveAsync($Seg, $Cts.Token)

      if (-not $Task.Wait($CdpTimeoutSec * 1000)) {
        throw "CDP receive timeout"
      }

      $Result = $Task.Result

      if ($Result.MessageType -eq [System.Net.WebSockets.WebSocketMessageType]::Close) {
        throw "CDP websocket closed"
      }

      $Ms.Write($Buffer, 0, $Result.Count)

    } while (-not $Result.EndOfMessage)
  } finally {
    $Cts.Dispose()
  }

  $Text = [System.Text.Encoding]::UTF8.GetString($Ms.ToArray())
  return $Text | ConvertFrom-Json
}

function Invoke-Cdp {
  param($Ws, $Id, $Method, $Params)

  Send-Cdp $Ws $Id $Method $Params

  $Limit = (Get-Date).AddSeconds($CdpTimeoutSec)

  while ((Get-Date) -lt $Limit) {
    $Msg = Receive-Cdp $Ws

    if ($Msg.id -eq $Id) {
      if ($Msg.error -ne $null) {
        throw "CDP error: id=$Id method=$Method message=$($Msg.error.message)"
      }

      return $Msg
    }
  }

  throw "CDP invoke timeout: id=$Id method=$Method"
}

function Get-Html-From-Edge {
  param($Port, $Url)

  $Target = Invoke-RestMethod -Method Put "http://127.0.0.1:$Port/json/new?about:blank"

  $TargetId = $Target.id
  $WsUrl = $Target.webSocketDebuggerUrl

  $Ws = [System.Net.WebSockets.ClientWebSocket]::new()
  $CtsConnect = New-Object System.Threading.CancellationTokenSource
  $CtsConnect.CancelAfter($CdpTimeoutSec * 1000)

  try {
    $ConnectTask = $Ws.ConnectAsync([Uri]$WsUrl, $CtsConnect.Token)

    if (-not $ConnectTask.Wait($CdpTimeoutSec * 1000)) {
      throw "CDP connect timeout"
    }
  } finally {
    $CtsConnect.Dispose()
  }

  try {
    Invoke-Cdp $Ws 1 'Network.enable' $null | Out-Null
    Invoke-Cdp $Ws 2 'Page.enable' $null | Out-Null

    Invoke-Cdp $Ws 3 'Network.setCookie' @{
      name = 'shared_perpage'
      value = '50'
      domain = 'kabutan.jp'
      path = '/'
    } | Out-Null

    Invoke-Cdp $Ws 4 'Page.navigate' @{
      url = $Url
    } | Out-Null

    Start-Sleep -Seconds 8

    $Res = Invoke-Cdp $Ws 5 'Runtime.evaluate' @{
      expression = 'document.documentElement.outerHTML'
      returnByValue = $true
    }

    $Html = $Res.result.result.value
  } finally {
    try {
      if ($Ws.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
        $Ws.CloseAsync(
          [System.Net.WebSockets.WebSocketCloseStatus]::NormalClosure,
          'done',
          [Threading.CancellationToken]::None
        ).Wait()
      }
    } catch {
    }

    try {
      Invoke-RestMethod -Method Get "http://127.0.0.1:$Port/json/close/$TargetId" | Out-Null
    } catch {
    }
  }

  return $Html
}

function Get-PageNumberFromUrl {
  param([string]$Url)

  if ($Url -match '[?&]page=(\d+)') {
    return [int]$Matches[1]
  }

  return 1
}

function Get-MaxPage06MorningNews {
  param([string]$Html)

  $MaxPage = $null

  if ($Html -match '<div class="meigara_count">[\s\S]*?<li>\s*([0-9,]+)銘柄\s*</li>') {
    $CountText = $Matches[1] -replace ',', ''
    $Count = [int]$CountText
    $MaxPage = [math]::Ceiling($Count / 50)

    Write-Host "[CHECK] morning news stock count = $Count"
    Write-Host "[CHECK] morning news max page by count = $MaxPage"

    return [int]$MaxPage
  }

  $PageMatches = [regex]::Matches($Html, 'page=(\d+)')

  foreach ($m in $PageMatches) {
    $PageNo = [int]$m.Groups[1].Value

    if ($MaxPage -eq $null -or $PageNo -gt $MaxPage) {
      $MaxPage = $PageNo
    }
  }

  if ($MaxPage -eq $null) {
    throw 'morning news max page not found'
  }

  Write-Host "[CHECK] morning news max page by pagination = $MaxPage"

  return [int]$MaxPage
}

function Test-KabutanMorningNews {
  param([string]$Html)

  $StockCount = [regex]::Matches($Html, '<td class="tac">\s*<a href="/stock/\?code=([0-9A-Z]{4})">').Count
  Write-Host "[CHECK] morning news stock row count = $StockCount"

  if ($StockCount -lt 1) {
    throw "morning news stock row count is zero: $StockCount"
  }

  return $true
}

function Test-Html-Basic {
  param(
    [string]$Html
  )

  if ([string]::IsNullOrWhiteSpace($Html)) {
    throw 'html is empty'
  }

  if ($Html.Length -lt 1000) {
    throw "html too short: $($Html.Length)"
  }

  if ($Html -notmatch '(?i)<html') {
    throw 'html does not contain html tag'
  }

  if ($Html -notmatch '(?i)</html>') {
    throw 'html does not contain closing html tag'
  }

  if ($Html -match 'アクセスが集中|しばらくしてから') {
    throw "html contains kabutan error word: $($Matches[0])"
  }

  return $true
}


function Test-Html-ByCheck {
  param(
    [string]$Html,
    [int]$Check
  )

  Test-Html-Basic $Html | Out-Null
  return $true
}

function Invoke-ItemDownload {
  param(
    $Item,
    [string]$SaveDir,
    $Utf8NoBom,
    [int]$Index,
    [int]$Total
  )

  $Url = $Item.Url
  $FileName = $Item.File
  $Check = 0

  if ($Item.ContainsKey('Check')) {
    $Check = [int]$Item.Check
  }

  $SavePath = Join-Path $SaveDir $FileName

  Write-Host "[INFO] $Index/$Total start"
  Write-Host $Url
  Write-Host "[INFO] check=$Check"

  $Html = Get-Html-From-Edge $Port $Url

  Test-Html-ByCheck $Html $Check | Out-Null

  [System.IO.File]::WriteAllText($SavePath, $Html, $Utf8NoBom)

  Write-Host "[OK] saved: $SavePath"
  
  return $Html
}

if ($TestMode -eq 1) {

  Write-Host "[TEST MODE]"
  Write-Host "[TEST] path : $TestHtmlPath"

  if (-not (Test-Path $TestHtmlPath)) {
    throw "test html not found: $TestHtmlPath"
  }

  $Html = Get-Content $TestHtmlPath -Raw -Encoding UTF8

  Write-Host "[TEST] html length = $($Html.Length)"

  try {
    Test-Html-ByCheck $Html 0 | Out-Null
    Write-Host "[TEST OK]"
  } catch {
    Write-Host "[TEST NG]"
    Write-Host $_
    throw
  }

  exit
}

if (-not (Test-Path $Edge)) {
  throw 'msedge.exe not found'
}

$Codes = Get-SecurityCodesFromMaster $MasterCsvPath
$Items = New-BasicInfoItems $Codes

New-Item -ItemType Directory -Force -Path $Profile | Out-Null

$SaveDir = Join-Path $SaveRoot (Get-Date -Format 'yyyyMMdd')
New-Item -ItemType Directory -Force -Path $SaveDir | Out-Null

Write-Host '[INFO] kill existing edge'

try {
  taskkill /F /IM msedge.exe 2>$null | Out-Null
} catch {
}

Start-Sleep -Seconds 5

Write-Host '[INFO] start edge'

Start-Process $Edge -ArgumentList "--remote-debugging-port=$Port --user-data-dir=`"$Profile`" about:blank"

Start-Sleep -Seconds 5
Wait-Cdp $Port

$Utf8NoBom = New-Object System.Text.UTF8Encoding $false

$RetryItems = @()
$FailedItems = @()

for ($i = 0; $i -lt $Items.Count; $i++) {

  $Item = $Items[$i]

  try {
    $Html = Invoke-ItemDownload `
      -Item $Item `
      -SaveDir $SaveDir `
      -Utf8NoBom $Utf8NoBom `
      -Index ($i + 1) `
      -Total $Items.Count
    
  } catch {
    Write-Host "[WARN] failed, queued for retry"
    Write-Host $Item.Url
    Write-Host $_

    $RetryItems += $Item
  }

  if ($i -lt $Items.Count - 1) {
    $Rand = Get-Random -Minimum 10 -Maximum 31
    $Wait = 40 + $Rand
    Write-Host "[INFO] wait $Wait sec"
    Start-Sleep -Seconds $Wait
  }
}

if ($RetryItems.Count -gt 0) {
  Write-Host "[INFO] retry start: $($RetryItems.Count) item(s)"

  for ($i = 0; $i -lt $RetryItems.Count; $i++) {
    $Item = $RetryItems[$i]

    try {
      $Rand = Get-Random -Minimum 20 -Maximum 41
      $Wait = 60 + $Rand
      Write-Host "[INFO] retry wait $Wait sec"
      Start-Sleep -Seconds $Wait

      $Html = Invoke-ItemDownload `
        -Item $Item `
        -SaveDir $SaveDir `
        -Utf8NoBom $Utf8NoBom `
        -Index ($i + 1) `
        -Total $Items.Count

    } catch {
      Write-Host "[ERROR] retry failed"
      Write-Host $Item.Url
      Write-Host $_

      $FailedItems += @{
        Url = $Item.Url
        File = $Item.File
        Check = $Item.Check
        Error = $_.ToString()
      }
    }
  }
}

if ($FailedItems.Count -gt 0) {
  Write-Host "[FATAL] failed item(s) remain: $($FailedItems.Count)"

  foreach ($Failed in $FailedItems) {
    Write-Host "----------------------------------------"
    Write-Host "URL   : $($Failed.Url)"
    Write-Host "File  : $($Failed.File)"
    Write-Host "Check : $($Failed.Check)"
    Write-Host "Error : $($Failed.Error)"
  }

  throw 'download finished with errors'
}

Write-Host '[DONE] all finished'
