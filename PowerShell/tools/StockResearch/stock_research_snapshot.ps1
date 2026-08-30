param(
  [Parameter(Mandatory = $true, Position = 0)]
  [ValidatePattern('^\d{4}$')]
  [string]$Code
)

$ErrorActionPreference = 'Stop'

# ============================================================
# Stock research material collector - COMPLETE
# Usage:
#   .\stock_research_snapshot_complete.ps1 -Code 5563
#
# Flow:
#   1) Create .\<Code>\
#   2) Copy TR_証券コード_連番3桁.txt -> TR_<Code>_001.txt
#   3) Start a dedicated Edge with CDP enabled
#   4) User logs in manually to Monex / Shikiho Online
#   5) Save target HTML pages
#   6) Download latest Monex PDFs: 説明会 / 短信 / 統合 / 有報
#   7) Save the latest 4 available Shikiho archive issue images
# ============================================================

$Edge = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
$Port = 9223
$Profile = Join-Path $PSScriptRoot 'browser-profile\edge-stock-research'
$CdpTimeoutSec = 90
$PageWaitSec = 5
$Utf8NoBom = New-Object System.Text.UTF8Encoding $false

$SaveDir = Join-Path (Get-Location) $Code

# 連番
$Serial = '001'

# 調査結果テンプレート
$TemplateSource = Join-Path $PSScriptRoot 'TR_証券コード_連番3桁.txt'
$TemplateDest = Join-Path $SaveDir ("TR_{0}_{1}.txt" -f $Code, $Serial)

# AIプロンプトテンプレート
$AiPromptSource = Join-Path (Get-Location) 'AIプロンプト.txt'
$AiPromptDest = Join-Path $SaveDir 'AIプロンプト.txt'

# キャッシュフローパターン画像
$CashFlowPatternSource = Join-Path (Get-Location) 'キャッシュフローパターン.png'
$CashFlowPatternDest = Join-Path $SaveDir 'キャッシュフローパターン.png'

$MonexBase = 'https://monex.ifis.co.jp/index.php'
$ShikihoBase = "https://shikiho.toyokeizai.net/stocks/$Code"

$HtmlItems = @(
  @{ File = '01_monex_find.html';             Url = "${MonexBase}?sa=find&ta=e&wd=$Code&x=0&y=0" },
  @{ File = '02_monex_chart.html';            Url = "${MonexBase}?sa=report_chart&bcode=$Code" },
  @{ File = '03_monex_segment.html';          Url = "${MonexBase}?sa=report_segment&bcode=$Code" },
  @{ File = '04_monex_announce_hist.html';    Url = "${MonexBase}?sa=report_announce_hist&bcode=$Code" },
  @{ File = '05_monex_dps.html';              Url = "${MonexBase}?sa=report_dps&bcode=$Code" },
  @{ File = '06_monex_est.html';              Url = "${MonexBase}?sa=report_est&bcode=$Code" },
  @{ File = '07_monex_index.html';            Url = "${MonexBase}?sa=report_index&bcode=$Code" },
  @{ File = '08_monex_theory_dps.html';       Url = "${MonexBase}?sa=report_theory_dps&bcode=$Code" },
  @{ File = '09_monex_topix.html';            Url = "${MonexBase}?sa=report_topix&bcode=$Code" },
  @{ File = '10_monex_disclose.html';         Url = "${MonexBase}?sa=report_disclose&bcode=$Code" },
  @{ File = '11_shikiho_top.html';            Url = $ShikihoBase },
  @{ File = '12_shikiho_corporate.html';      Url = "$ShikihoBase/corporate" },
  @{ File = '13_shikiho_forecast.html';       Url = "$ShikihoBase/forecast" },
  @{ File = '14_shikiho_performance.html';    Url = "$ShikihoBase/performance" },
  @{ File = '15_shikiho_timeline.html';       Url = "$ShikihoBase/timeline" },
  @{ File = '16_personal_master_view.html';   Url = "https://www.teitenkansoku.online/api/master_view.php?mode=api&text=$Code" },
  @{ File = '17_karauri_ranking.html';        Url = "https://karauri.net/$Code/" }
)

function Wait-Cdp {
  param([int]$Port)
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
  param($Ws, [int]$Id, [string]$Method, $Params)
  $Obj = @{ id = $Id; method = $Method }
  if ($null -ne $Params) { $Obj.params = $Params }
  $Json = $Obj | ConvertTo-Json -Depth 30 -Compress
  $Bytes = [System.Text.Encoding]::UTF8.GetBytes($Json)
  $Seg = New-Object System.ArraySegment[byte] -ArgumentList @(,$Bytes)
  $Cts = New-Object System.Threading.CancellationTokenSource
  $Cts.CancelAfter($CdpTimeoutSec * 1000)
  try {
    $Task = $Ws.SendAsync($Seg, [System.Net.WebSockets.WebSocketMessageType]::Text, $true, $Cts.Token)
    if (-not $Task.Wait($CdpTimeoutSec * 1000)) { throw "CDP send timeout: $Method" }
  } finally { $Cts.Dispose() }
}

function Receive-CdpRaw {
  param($Ws)
  $Buffer = New-Object byte[] 1048576
  $Ms = New-Object System.IO.MemoryStream
  $Cts = New-Object System.Threading.CancellationTokenSource
  $Cts.CancelAfter($CdpTimeoutSec * 1000)
  try {
    do {
      $Seg = New-Object System.ArraySegment[byte] -ArgumentList @(,$Buffer)
      $Task = $Ws.ReceiveAsync($Seg, $Cts.Token)
      if (-not $Task.Wait($CdpTimeoutSec * 1000)) { throw 'CDP receive timeout' }
      $Result = $Task.Result
      if ($Result.MessageType -eq [System.Net.WebSockets.WebSocketMessageType]::Close) { throw 'CDP websocket closed' }
      $Ms.Write($Buffer, 0, $Result.Count)
    } while (-not $Result.EndOfMessage)
  } finally { $Cts.Dispose() }
  return [System.Text.Encoding]::UTF8.GetString($Ms.ToArray())
}

function Invoke-Cdp {
  param($Ws, [int]$Id, [string]$Method, $Params)
  Send-Cdp $Ws $Id $Method $Params
  $Limit = (Get-Date).AddSeconds($CdpTimeoutSec)
  $IdPattern = '"id"\s*:\s*' + [regex]::Escape([string]$Id) + '(?=\s*[,}])'
  while ((Get-Date) -lt $Limit) {
    $Raw = Receive-CdpRaw $Ws

    # CDP emits many asynchronous Network/Page events. Some contain HTTP
    # headers whose names differ only by case (Cache-Control/cache-control).
    # Windows PowerShell 5.1 ConvertFrom-Json treats keys case-insensitively
    # and fails on those events. Only parse the response for our command ID.
    if ($Raw -notmatch $IdPattern) { continue }

    $Msg = $Raw | ConvertFrom-Json
    if ($null -ne $Msg.error) { throw "CDP error: $Method : $($Msg.error.message)" }
    return $Msg
  }
  throw "CDP invoke timeout: $Method"
}

function New-CdpPage {
  $Target = Invoke-RestMethod -Method Put "http://127.0.0.1:$Port/json/new?about:blank"
  $Ws = [System.Net.WebSockets.ClientWebSocket]::new()
  $Cts = New-Object System.Threading.CancellationTokenSource
  $Cts.CancelAfter($CdpTimeoutSec * 1000)
  try {
    $Task = $Ws.ConnectAsync([Uri]$Target.webSocketDebuggerUrl, $Cts.Token)
    if (-not $Task.Wait($CdpTimeoutSec * 1000)) { throw 'CDP connect timeout' }
  } finally { $Cts.Dispose() }
  Invoke-Cdp $Ws 1 'Network.enable' $null | Out-Null
  Invoke-Cdp $Ws 2 'Page.enable' $null | Out-Null
  return @{ Ws = $Ws; TargetId = $Target.id }
}

function Close-CdpPage {
  param($Page)
  try {
    $Ws = $Page.Ws
    if ($Ws.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
      $Ws.CloseAsync([System.Net.WebSockets.WebSocketCloseStatus]::NormalClosure, 'done', [Threading.CancellationToken]::None).Wait()
    }
    $Ws.Dispose()
  } catch {}
  try { Invoke-RestMethod -Method Get "http://127.0.0.1:$Port/json/close/$($Page.TargetId)" | Out-Null } catch {}
}

function Invoke-Js {
  param($Ws, [string]$Expression, [int]$Id = 20)
  $Res = Invoke-Cdp $Ws $Id 'Runtime.evaluate' @{
    expression = $Expression
    returnByValue = $true
    awaitPromise = $true
  }
  if ($null -ne $Res.result.exceptionDetails) { throw 'JavaScript evaluation failed' }
  return $Res.result.result.value
}

function Navigate-Cdp {
  param($Ws, [string]$Url, [int]$WaitSec = $PageWaitSec)
  Invoke-Cdp $Ws 10 'Page.navigate' @{ url = $Url } | Out-Null
  $Limit = (Get-Date).AddSeconds($CdpTimeoutSec)
  do {
    Start-Sleep -Milliseconds 500
    try {
      $State = Invoke-Js $Ws 'document.readyState' 11
      if ($State -eq 'complete') { break }
    } catch {}
  } while ((Get-Date) -lt $Limit)
  Start-Sleep -Seconds $WaitSec
}

function Get-Html-From-Url {
  param([string]$Url)
  $Page = New-CdpPage
  try {
    Navigate-Cdp $Page.Ws $Url
    $Html = Invoke-Js $Page.Ws 'document.documentElement.outerHTML' 30
    if ([string]::IsNullOrWhiteSpace([string]$Html)) { throw "empty HTML: $Url" }
    return [string]$Html
  } finally { Close-CdpPage $Page }
}

function Save-Html-From-Url {
  param([string]$Url, [string]$Path)
  Write-Host "[HTML] $Url"
  $Html = Get-Html-From-Url $Url
  [System.IO.File]::WriteAllText($Path, $Html, $Utf8NoBom)
  Write-Host "[OK]   $Path ($($Html.Length) chars)"
  return $Html
}

function Get-LatestMonexDocumentLinksFromPage {
  param($Ws)

  $Expr = @'
(function () {
  return Array.from(document.querySelectorAll('table.edinet_matrix a')).map(function (a) {
    return {
      text: (a.textContent || '').replace(/\s+/g,' ').trim(),
      href: a.href || '',
      title: a.title || ''
    };
  });
})()
'@
  $Links = @(Invoke-Js $Ws $Expr 60)

  $Kinds = @('説明会','短信','統合','有報')
  $Result = @()

  foreach ($Kind in $Kinds) {
    $Candidates = foreach ($L in $Links) {
      $Text = [string]$L.text
      if ($Text -match ('^' + [regex]::Escape($Kind) + '\((\d{2})/(\d{2})/(\d{2})\)')) {
        $Year = 2000 + [int]$Matches[1]
        $Date = Get-Date -Year $Year -Month ([int]$Matches[2]) -Day ([int]$Matches[3])
        [pscustomobject]@{
          Kind  = $Kind
          Date  = $Date
          Text  = $Text
          Href  = [string]$L.href
          Title = [string]$L.title
        }
      }
    }

    if ($Kind -eq '短信') {
      # 短信は最新から過去4期分
      $Selected = @(
        $Candidates |
          Sort-Object Date -Descending |
          Select-Object -First 4
      )

      foreach ($Item in $Selected) {
        if ($null -ne $Item) {
          $Result += $Item
        }
      }
    } else {
      # 説明会・統合・有報は従来どおり最新1件
      $Latest = $Candidates |
        Sort-Object Date -Descending |
        Select-Object -First 1

      if ($null -ne $Latest) {
        $Result += $Latest
      }
    }
  }

  return $Result
}

function Start-EdgeAuthenticatedFetch {
  param($Ws, [string]$Url)

  # IMPORTANT:
  # This fetch runs INSIDE the already authenticated Monex page.
  # Therefore Edge itself supplies HttpOnly/session cookies and Referer.
  $UrlJson = $Url | ConvertTo-Json -Compress

  $Expr = @"
(async function () {
  try {
    const url = $UrlJson;
    const response = await fetch(url, {
      method: 'GET',
      credentials: 'include',
      redirect: 'follow',
      cache: 'no-store'
    });

    const contentType = response.headers.get('content-type') || '';
    const contentDisposition = response.headers.get('content-disposition') || '';
    const buffer = await response.arrayBuffer();

    window.__monexDownloadBytes = new Uint8Array(buffer);
    window.__monexDownloadMeta = {
      ok: response.ok,
      status: response.status,
      url: response.url || url,
      contentType: contentType,
      contentDisposition: contentDisposition,
      length: buffer.byteLength
    };

    return window.__monexDownloadMeta;
  } catch (e) {
    window.__monexDownloadBytes = null;
    window.__monexDownloadMeta = {
      ok: false,
      status: 0,
      url: '',
      contentType: '',
      contentDisposition: '',
      length: 0,
      error: String(e && e.stack ? e.stack : e)
    };
    return window.__monexDownloadMeta;
  }
})()
"@

  return Invoke-Js $Ws $Expr 70
}

function Get-EdgeFetchChunkBase64 {
  param(
    $Ws,
    [int]$Start,
    [int]$Length
  )

  $Expr = @"
(function () {
  if (!window.__monexDownloadBytes) {
    throw new Error('download buffer is empty');
  }

  const start = $Start;
  const end = Math.min(start + $Length, window.__monexDownloadBytes.length);
  const bytes = window.__monexDownloadBytes.subarray(start, end);

  let binary = '';
  const step = 0x8000;
  for (let i = 0; i < bytes.length; i += step) {
    const part = bytes.subarray(i, Math.min(i + step, bytes.length));
    binary += String.fromCharCode.apply(null, part);
  }
  return btoa(binary);
})()
"@

  return [string](Invoke-Js $Ws $Expr 71)
}

function Clear-EdgeFetchBuffer {
  param($Ws)
  try {
    Invoke-Js $Ws 'window.__monexDownloadBytes=null; window.__monexDownloadMeta=null; true;' 72 | Out-Null
  } catch {}
}

function Save-EdgeFetchedFile {
  param(
    $Ws,
    [string]$Url,
    [string]$OutFile
  )

  $Meta = Start-EdgeAuthenticatedFetch -Ws $Ws -Url $Url

  if ($null -eq $Meta) {
    throw "Edge fetch returned no metadata: $Url"
  }

  if ($Meta.error) {
    throw "Edge fetch failed: $($Meta.error)"
  }

  $Length = [int64]$Meta.length
  if ($Length -le 0) {
    throw "Edge fetch returned empty body: HTTP $($Meta.status) $($Meta.url)"
  }

  Write-Host ("      HTTP={0} type={1} bytes={2} final={3}" -f `
    $Meta.status, $Meta.contentType, $Length, $Meta.url)

  $Stream = $null
  try {
    $Stream = [System.IO.File]::Open(
      $OutFile,
      [System.IO.FileMode]::Create,
      [System.IO.FileAccess]::Write,
      [System.IO.FileShare]::None
    )

    # Keep each CDP response reasonably small.
    $ChunkSize = 512 * 1024
    for ($Offset = [int64]0; $Offset -lt $Length; $Offset += $ChunkSize) {
      $ThisSize = [int][Math]::Min($ChunkSize, $Length - $Offset)
      $Base64 = Get-EdgeFetchChunkBase64 -Ws $Ws -Start ([int]$Offset) -Length $ThisSize
      $Bytes = [Convert]::FromBase64String($Base64)
      $Stream.Write($Bytes, 0, $Bytes.Length)

      $Done = [Math]::Min($Offset + $ThisSize, $Length)
      Write-Host ("      {0:N0}/{1:N0} bytes" -f $Done, $Length)
    }
  } finally {
    if ($null -ne $Stream) { $Stream.Dispose() }
    Clear-EdgeFetchBuffer $Ws
  }

  $Bytes5 = New-Object byte[] 5
  $Fs = [System.IO.File]::OpenRead($OutFile)
  try {
    $Read = $Fs.Read($Bytes5, 0, 5)
  } finally {
    $Fs.Dispose()
  }

  $Signature = if ($Read -gt 0) {
    [System.Text.Encoding]::ASCII.GetString($Bytes5, 0, $Read)
  } else {
    ''
  }

  if ($Signature -ne '%PDF-') {
    $DebugFile = [System.IO.Path]::ChangeExtension($OutFile, '.not_pdf.html')
    Move-Item -Force $OutFile $DebugFile
    throw ("Edge-authenticated response is not PDF. HTTP={0} type={1} final={2} saved={3}" -f `
      $Meta.status, $Meta.contentType, $Meta.url, $DebugFile)
  }
}

function Save-LatestMonexPdfs {
  param([string]$DiscloseUrl)

  Write-Host '[INFO] finding latest Monex documents'

  # Keep ONE authenticated Monex page alive for both link extraction and fetch.
  # This is the key difference from previous versions.
  $Page = New-CdpPage
  try {
    Navigate-Cdp $Page.Ws $DiscloseUrl

    $Title = [string](Invoke-Js $Page.Ws 'document.title' 61)
    if ($Title -match 'エラー') {
      throw "Monex disclose page is not authenticated: $Title"
    }

    $Docs = @(Get-LatestMonexDocumentLinksFromPage $Page.Ws)
    if ($Docs.Count -eq 0) {
      throw 'No Monex document links found. Check login state.'
    }

    $No = 1
    foreach ($Doc in $Docs) {
      $DateText = $Doc.Date.ToString('yyyyMMdd')
      $OutFile = Join-Path $SaveDir ("20_monex_{0:D2}_{1}_{2}.pdf" -f $No, $Doc.Kind, $DateText)

      Write-Host "[PDF] $($Doc.Text)"
      Write-Host "      $($Doc.Href)"

      try {
        Save-EdgeFetchedFile -Ws $Page.Ws -Url $Doc.Href -OutFile $OutFile
        Write-Host "[OK]   $OutFile"
      } catch {
        Write-Warning "PDF download failed: $($Doc.Text) : $_"
      }

      $No++
    }
  } finally {
    Close-CdpPage $Page
  }
}

function Save-ElementScreenshot {
  param($Ws, [string]$OutFile)
  $Expr = @'
(function () {
  var els = Array.from(document.querySelectorAll('img,canvas,svg,iframe,embed,object')).filter(function (e) {
    var r = e.getBoundingClientRect();
    var s = getComputedStyle(e);
    return r.width >= 250 && r.height >= 250 && s.display !== 'none' && s.visibility !== 'hidden';
  });
  if (!els.length) return null;
  els.sort(function (a,b) {
    var ar=a.getBoundingClientRect(), br=b.getBoundingClientRect();
    return (br.width*br.height)-(ar.width*ar.height);
  });
  var e=els[0], r=e.getBoundingClientRect();
  return { x:r.left+scrollX, y:r.top+scrollY, width:r.width, height:r.height, tag:e.tagName };
})()
'@
  $Rect = Invoke-Js $Ws $Expr 70
  if ($null -eq $Rect) { return $false }
  $Res = Invoke-Cdp $Ws 71 'Page.captureScreenshot' @{
    format = 'png'
    captureBeyondViewport = $true
    clip = @{ x=[double]$Rect.x; y=[double]$Rect.y; width=[double]$Rect.width; height=[double]$Rect.height; scale=1 }
  }
  $Bytes = [Convert]::FromBase64String([string]$Res.result.data)
  [System.IO.File]::WriteAllBytes($OutFile, $Bytes)
  return $true
}

function Save-ShikihoArchiveImages {
  param([string]$ArchiveUrl)

  Write-Host '[INFO] saving latest 4 Shikiho archive issues'
  Write-Host '[INFO] strategy: current issue -> click "前号" three times'

  $Page = New-CdpPage
  try {
    Navigate-Cdp $Page.Ws $ArchiveUrl 7

    for ($i = 0; $i -lt 4; $i++) {
      # Try to obtain a readable issue label from visible page text.
      $LabelExpr = @'
(function () {
  var t=(document.body ? document.body.innerText : '').replace(/\s+/g,' ');
  var m=t.match(/(20\d{2})[^。\n]{0,20}(新春|春|夏|秋)(?:号)?/);
  return m ? (m[1]+'_'+m[2]) : '';
})()
'@
      $Label = [string](Invoke-Js $Page.Ws $LabelExpr (100 + $i))
      if ([string]::IsNullOrWhiteSpace($Label)) {
        if ($i -eq 0) { $Label = 'latest' } else { $Label = "prev_$i" }
      }
      $Label = ($Label -replace '[\\/:*?"<>|]', '_' -replace '\s+', '_').Trim('_')
      $OutFile = Join-Path $SaveDir ("30_shikiho_{0:D2}_{1}.png" -f ($i + 1), $Label)

      if (Save-ElementScreenshot -Ws $Page.Ws -OutFile $OutFile) {
        Write-Host "[OK]   $OutFile"
      } else {
        # Fallback: capture the whole visible viewport when the PDF/image is not a normal DOM image element.
        $Res = Invoke-Cdp $Page.Ws (120 + $i) 'Page.captureScreenshot' @{
          format = 'png'
          captureBeyondViewport = $false
        }
        $Bytes = [Convert]::FromBase64String([string]$Res.result.data)
        [System.IO.File]::WriteAllBytes($OutFile, $Bytes)
        Write-Host "[OK]   $OutFile (viewport fallback)"
      }

      if ($i -ge 3) { break }

      # Move to the previous issue. Official Shikiho archive UI provides a "前号" control.
      $PrevExpr = @'
(function () {
  var els=Array.from(document.querySelectorAll('a,button,[role="button"]'));
  var e=els.find(function(x){
    var t=((x.innerText||x.textContent||'')+' '+(x.title||'')+' '+(x.getAttribute('aria-label')||'')).replace(/\s+/g,' ').trim();
    var c=(x.className||'').toString();
    var disabled=x.disabled || x.getAttribute('aria-disabled')==='true' || /disabled/i.test(c);
    return !disabled && /前号/.test(t);
  });
  if (!e) return false;
  e.scrollIntoView({block:'center'});
  e.click();
  return true;
})()
'@
      $Clicked = Invoke-Js $Page.Ws $PrevExpr (140 + $i)
      if (-not $Clicked) {
        Write-Warning '"前号" control was not found. Saved all issues available up to this point.'
        break
      }
      Start-Sleep -Seconds 6
    }
  } catch {
    Write-Warning "Shikiho archive image capture failed: $_"
  } finally {
    Close-CdpPage $Page
  }
}

# -------------------- main --------------------
if (-not (Test-Path $Edge)) { throw "msedge.exe not found: $Edge" }

New-Item -ItemType Directory -Force -Path $SaveDir | Out-Null
New-Item -ItemType Directory -Force -Path $Profile | Out-Null

Write-Host "[INFO] stock research full mode: $Code"
Write-Host "[INFO] output folder: $SaveDir"

# Template
if (Test-Path $TemplateSource) {
  Copy-Item $TemplateSource $TemplateDest -Force
  Write-Host "[OK] template copied: $TemplateDest"
} else {
  Write-Warning "Template not found next to script: $TemplateSource"
}

# AI prompt template
if (Test-Path $AiPromptSource) {
  $AiPromptText = [System.IO.File]::ReadAllText($AiPromptSource)

  $AiPromptText = $AiPromptText.Replace('★★★', $Code)
  $AiPromptText = $AiPromptText.Replace('■■■', $Serial)

  [System.IO.File]::WriteAllText(
    $AiPromptDest,
    $AiPromptText,
    $Utf8NoBom
  )

  Write-Host "[OK] AI prompt copied: $AiPromptDest"
} else {
  Write-Warning "AI prompt template not found: $AiPromptSource"
}

# Cash flow pattern image
if (Test-Path $CashFlowPatternSource) {
  Copy-Item $CashFlowPatternSource $CashFlowPatternDest -Force
  Write-Host "[OK] cash flow pattern copied: $CashFlowPatternDest"
} else {
  Write-Warning "Cash flow pattern image not found: $CashFlowPatternSource"
}

# Dedicated Edge. Do not kill the user's normal Edge.
Write-Host '[INFO] starting dedicated Edge for stock research'
Start-Process $Edge -ArgumentList @(
  "--remote-debugging-port=$Port",
  "--user-data-dir=`"$Profile`"",
  'https://monex.ifis.co.jp/'
)
Wait-Cdp $Port
Start-Sleep -Seconds 2

Start-Process $Edge -ArgumentList @(
  "--remote-debugging-port=$Port",
  "--user-data-dir=`"$Profile`"",
  'https://shikiho.toyokeizai.net/'
)

Write-Host ''
Write-Host '============================================================'
Write-Host ' Edgeで以下を手動で済ませてください。'
Write-Host '  1. マネックスへログインし、銘柄スカウターを開ける状態にする'
Write-Host '  2. 会社四季報オンラインへログインする'
Write-Host ' 完了したら、このPowerShell画面に戻って Enter を押してください。'
Write-Host '============================================================'
[void](Read-Host)

# ------------------------------------------------------------
# 1) HTML
# ------------------------------------------------------------
Write-Host ''
Write-Host '[PHASE 1/3] HTML collection'

$Failed = @()
foreach ($Item in $HtmlItems) {
  try {
    $Path = Join-Path $SaveDir $Item.File
    Save-Html-From-Url -Url $Item.Url -Path $Path | Out-Null
  } catch {
    Write-Warning "HTML save failed: $($Item.Url) : $_"
    $Failed += $Item.Url
  }
}

# Shikiho archive HTML is also part of the requested HTML set.
$ArchiveUrl = "$ShikihoBase/shikiho"
try {
  $ArchiveHtmlPath = Join-Path $SaveDir '18_shikiho_archive.html'
  Save-Html-From-Url -Url $ArchiveUrl -Path $ArchiveHtmlPath | Out-Null
} catch {
  Write-Warning "Shikiho archive HTML save failed: $_"
  $Failed += $ArchiveUrl
}

# ------------------------------------------------------------
# 2) Monex PDFs
# ------------------------------------------------------------
Write-Host ''
Write-Host '[PHASE 2/3] Monex PDFs'

$DiscloseUrl = "${MonexBase}?sa=report_disclose&bcode=$Code"
try {
  Save-LatestMonexPdfs -DiscloseUrl $DiscloseUrl
} catch {
  Write-Warning "Monex PDF phase failed: $_"
}

# ------------------------------------------------------------
# 3) Shikiho archive images
# ------------------------------------------------------------
Write-Host ''
Write-Host '[PHASE 3/3] Shikiho archive images'

try {
  Save-ShikihoArchiveImages -ArchiveUrl $ArchiveUrl
} catch {
  Write-Warning "Shikiho image phase failed: $_"
}

Write-Host ''
Write-Host '============================================================'
if ($Failed.Count -gt 0) {
  Write-Warning "Completed with $($Failed.Count) HTML failure(s)."
  foreach ($U in $Failed) { Write-Host "  NG: $U" }
} else {
  Write-Host '[DONE] all requested HTML pages were saved.'
}
Write-Host '[DONE] Monex PDF phase finished.'
Write-Host '[DONE] Shikiho image phase finished.'
Write-Host "[DONE] output: $SaveDir"
Write-Host '============================================================'
