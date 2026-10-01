# デバック時の4桁証券コードの単体起動コマンド
# powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\stock_research_ai_prepare.ps1" -Code 4183
param(
  [Parameter(Mandatory = $false, Position = 0)]
  [ValidatePattern('^\d{4}$')]
  [string]$Code
)

# Directory layout
$RootDir = Split-Path -Parent $PSScriptRoot
$ResultRoot = Join-Path $RootDir 'result'
New-Item -ItemType Directory -Path $ResultRoot -Force | Out-Null

$ErrorActionPreference = 'Stop'
$Utf8NoBom = New-Object System.Text.UTF8Encoding $false
$Serial = '001'
$ResearchListFile = Join-Path $RootDir '調査企業リスト.txt'
$QpdfExe = Join-Path $RootDir 'tools\qpdf\bin\qpdf.exe'
$RunMapFile = Join-Path $RootDir 'state\current_research_run.json'

# ============================================================
# StockResearch - GPT upload preparation
#
# Normal:
#   powershell -File .\stock_research_ai_prepare.ps1
#   -> reads 調査企業リスト.txt
#
# Debug:
#   powershell -File .\stock_research_ai_prepare.ps1 -Code 4183
#   -> processes only 4183
#
# Output:
#   <code>\gpt_upload\
#
# PDF merge tool:
#   .\tools\qpdf\bin\qpdf.exe
# Python installation is not used by this script.
#
# AIプロンプト.txt is NOT copied to gpt_upload.
# The later ChatGPT automation will paste its contents directly.
# ============================================================

function Get-ResearchCodes {
  if (-not (Test-Path -LiteralPath $ResearchListFile)) {
    throw "調査企業リスト.txt が見つかりません: $ResearchListFile"
  }
  $Result = @()
  foreach ($Line in @(Get-Content -LiteralPath $ResearchListFile -Encoding UTF8)) {
    $Text = ([string]$Line).Trim()
    if ([string]::IsNullOrWhiteSpace($Text)) { continue }
    if ($Text.StartsWith('#')) { continue }
    if ($Text -notmatch '^(\d{4})(?:\s+.*)?$') {
      throw "調査企業リスト.txt に不正な行があります: [$Text]"
    }
    $Result += $Matches[1]
  }
  if ($Result.Count -eq 0) { throw '調査企業リスト.txt に実行対象の証券コードがありません。' }
  return @($Result)
}

function Get-RunDirectory {
  param([Parameter(Mandatory = $true)][string]$StockCode)

  if (-not (Test-Path -LiteralPath $RunMapFile -PathType Leaf)) {
    throw "current_research_run.json がありません: $RunMapFile"
  }
  $Map = Get-Content -LiteralPath $RunMapFile -Raw -Encoding UTF8 | ConvertFrom-Json
  $RunName = [string]$Map.runs.$StockCode
  if ([string]::IsNullOrWhiteSpace($RunName)) {
    throw "current_research_run.json に $StockCode の実行フォルダがありません。"
  }
  return Join-Path (Join-Path $ResultRoot $StockCode) $RunName
}

function Remove-ClearlyUnnecessaryHtml {
  param(
    [Parameter(Mandatory = $true)][string]$Html
  )

  # 元HTMLの構造をできるだけ保持する。
  # 削除するのは、明確に本文ではない以下の要素だけ。
  # table/tr/td/th/div/span/a 等のタグ・属性はそのまま残す。
  $Work = $Html
  $Work = [regex]::Replace($Work, '(?is)<!--.*?-->', '')
  $Work = [regex]::Replace($Work, '(?is)<script\b[^>]*>.*?</script\s*>', '')
  $Work = [regex]::Replace($Work, '(?is)<style\b[^>]*>.*?</style\s*>', '')
  $Work = [regex]::Replace($Work, '(?is)<noscript\b[^>]*>.*?</noscript\s*>', '')

  return $Work.Trim()
}

function Merge-PdfFiles {
  param(
    [Parameter(Mandatory = $true)][System.IO.FileInfo[]]$PdfFiles,
    [Parameter(Mandatory = $true)][string]$OutputFile
  )

  if ($PdfFiles.Count -eq 0) {
    return $false
  }

  if (-not (Test-Path -LiteralPath $QpdfExe -PathType Leaf)) {
    throw "qpdf.exe が見つかりません: $QpdfExe"
  }

  # qpdf --empty --pages file1.pdf 1-z file2.pdf 1-z ... -- output.pdf
  # 引数配列で渡すため、パスに空白や日本語が含まれていても安全に扱えます。
  $Args = @('--empty', '--pages')
  foreach ($Pdf in $PdfFiles) {
    $Args += $Pdf.FullName
    $Args += '1-z'
  }
  $Args += '--'
  $Args += $OutputFile

  Write-Host ("[QPDF] {0}" -f $QpdfExe)
  foreach ($Pdf in $PdfFiles) {
    Write-Host ("       + {0}" -f $Pdf.Name)
  }

  & $QpdfExe @Args
  $Exit = $LASTEXITCODE

  # qpdf exit code:
  #   0 = success
  #   2 = error
  #   3 = completed with warnings
  # qpdf 12.x may return 3 for a usable PDF when source PDFs contain warnings.
  # Preserve the output and continue for 0/3; only 2 (or other unexpected codes) is fatal.
  if ($Exit -notin @(0, 3)) {
    throw "qpdf によるPDF結合に失敗しました。exit code=$Exit"
  }
  if ($Exit -eq 3) {
    Write-Warning "qpdf は警告付きでPDFを結合しました。出力PDFを --check で再検証します。exit code=3"
  }

  if (-not (Test-Path -LiteralPath $OutputFile -PathType Leaf)) {
    throw "qpdf は正常終了しましたが、出力PDFが見つかりません: $OutputFile"
  }

  $OutInfo = Get-Item -LiteralPath $OutputFile
  if ($OutInfo.Length -le 0) {
    throw "結合PDFのサイズが0バイトです: $OutputFile"
  }

  # 生成したPDFをqpdf自身でも検証します。
  & $QpdfExe '--check' $OutputFile
  $CheckExit = $LASTEXITCODE
  if ($CheckExit -notin @(0, 3)) {
    throw "結合後PDFの qpdf --check に失敗しました。exit code=$CheckExit"
  }
  if ($CheckExit -eq 3) {
    Write-Warning "結合後PDFは qpdf --check で警告がありますが、致命的エラーではありません。exit code=3"
  }

  return $true
}

function Prepare-OneStock {
  param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^\d{4}$')]
    [string]$StockCode
  )

  $StockDir = Get-RunDirectory -StockCode $StockCode
  if (-not (Test-Path -LiteralPath $StockDir -PathType Container)) {
    throw "証券コードフォルダがありません: $StockDir"
  }

  $UploadDir = Join-Path $StockDir 'gpt_upload'
  if (Test-Path -LiteralPath $UploadDir) {
    Remove-Item -LiteralPath $UploadDir -Recurse -Force
  }
  New-Item -ItemType Directory -Force -Path $UploadDir | Out-Null

  Write-Host ''
  Write-Host '############################################################'
  Write-Host "[START] GPT upload preparation: $StockCode"
  Write-Host "[OUT]   $UploadDir"
  Write-Host '############################################################'

  # 1) HTML -> one HTML file, preserving original HTML structure
  $HtmlFiles = @(
    Get-ChildItem -LiteralPath $StockDir -File -Filter '*.html' |
      Where-Object { $_.Name -match '^\d+_' } |
      Sort-Object Name
  )

  $WebOut = Join-Path $UploadDir ("01_{0}_WEB資料.html" -f $StockCode)
  $Builder = New-Object System.Text.StringBuilder

  [void]$Builder.AppendLine('<!DOCTYPE html>')
  [void]$Builder.AppendLine('<html lang="ja">')
  [void]$Builder.AppendLine('<head>')
  [void]$Builder.AppendLine('<meta charset="utf-8">')
  [void]$Builder.AppendLine("<title>$StockCode WEB資料</title>")
  [void]$Builder.AppendLine('</head>')
  [void]$Builder.AppendLine('<body>')
  [void]$Builder.AppendLine("<!-- 証券コード: $StockCode -->")
  [void]$Builder.AppendLine("<!-- 生成日時: $((Get-Date).ToString('yyyy/MM/dd HH:mm:ss')) -->")
  [void]$Builder.AppendLine("<!-- 元HTML数: $($HtmlFiles.Count) -->")
  [void]$Builder.AppendLine('')

  foreach ($HtmlFile in $HtmlFiles) {
    Write-Host "[HTML] $($HtmlFile.Name)"

    $Html = [System.IO.File]::ReadAllText($HtmlFile.FullName)
    $CleanHtml = Remove-ClearlyUnnecessaryHtml -Html $Html

    # 元HTML内の既存コメントを削除した後で、SOURCE境界コメントを付与する。
    [void]$Builder.AppendLine('<!-- ============================================================ -->')
    [void]$Builder.AppendLine("<!-- SOURCE: $($HtmlFile.Name) -->")
    [void]$Builder.AppendLine('<!-- ============================================================ -->')
    [void]$Builder.AppendLine($CleanHtml)
    [void]$Builder.AppendLine('')
  }

  [void]$Builder.AppendLine('</body>')
  [void]$Builder.AppendLine('</html>')

  [System.IO.File]::WriteAllText($WebOut, $Builder.ToString(), $Utf8NoBom)
  Write-Host "[OK] $WebOut"

  # 2) PDFs -> one PDF, preserving source filename order
  $PdfFiles = @(
    Get-ChildItem -LiteralPath $StockDir -File -Filter '20_monex_*.pdf' |
      Sort-Object Name
  )
  if ($PdfFiles.Count -gt 0) {
    $PdfOut = Join-Path $UploadDir ("02_{0}_IR資料.pdf" -f $StockCode)
    Write-Host ("[PDF] {0} files -> {1}" -f $PdfFiles.Count, $PdfOut)
    [void](Merge-PdfFiles -PdfFiles $PdfFiles -OutputFile $PdfOut)
    Write-Host "[OK] $PdfOut"
  } else {
    Write-Warning "[$StockCode] 20_monex_*.pdf がありません。"
  }

  # 3) Shikiho PNGs stay separate and untouched
  $ShikihoImages = @(
    Get-ChildItem -LiteralPath $StockDir -File -Filter '30_shikiho_*.png' |
      Sort-Object Name
  )
  $No = 3
  foreach ($Img in $ShikihoImages) {
    $Suffix = $Img.Name -replace '^30_shikiho_\d+_', ''
    $Dest = Join-Path $UploadDir ("{0:D2}_shikiho_{1}" -f $No, $Suffix)
    Copy-Item -LiteralPath $Img.FullName -Destination $Dest -Force
    Write-Host "[OK] $Dest"
    $No++
  }

  # 4) Cash-flow reference image
  $CashFlow = Join-Path $StockDir 'キャッシュフローパターン.png'
  if (Test-Path -LiteralPath $CashFlow) {
    $Dest = Join-Path $UploadDir ("{0:D2}_キャッシュフローパターン.png" -f $No)
    Copy-Item -LiteralPath $CashFlow -Destination $Dest -Force
    Write-Host "[OK] $Dest"
    $No++
  } else {
    Write-Warning "[$StockCode] キャッシュフローパターン.png がありません。"
  }

  # 5) TR file
  $TrFile = Join-Path $StockDir ("TR_{0}_{1}.txt" -f $StockCode, $Serial)
  if (Test-Path -LiteralPath $TrFile) {
    $Dest = Join-Path $UploadDir ("{0:D2}_TR_{1}_{2}.txt" -f $No, $StockCode, $Serial)
    Copy-Item -LiteralPath $TrFile -Destination $Dest -Force
    Write-Host "[OK] $Dest"
  } else {
    Write-Warning "[$StockCode] TRファイルがありません: $TrFile"
  }

  $UploadFiles = @(Get-ChildItem -LiteralPath $UploadDir -File | Sort-Object Name)
  Write-Host ''
  Write-Host ("[DONE] {0}: gpt_upload files = {1}" -f $StockCode, $UploadFiles.Count)
  foreach ($F in $UploadFiles) {
    Write-Host ("  {0}  ({1:N0} bytes)" -f $F.Name, $F.Length)
  }
  if ($UploadFiles.Count -gt 10) {
    Write-Warning "[$StockCode] gpt_upload が10ファイルを超えています。現在: $($UploadFiles.Count)"
  }
}

if (-not [string]::IsNullOrWhiteSpace($Code)) {
  $Codes = @($Code)
  Write-Host "[INFO] デバッグ単一銘柄モード: $Code"
} else {
  $Codes = @(Get-ResearchCodes)
  Write-Host ("[INFO] 調査企業リストモード: {0} 銘柄" -f $Codes.Count)
}

$Results = @()
foreach ($C in $Codes) {
  try {
    Prepare-OneStock -StockCode $C
    $Results += [pscustomobject]@{ Code=$C; Status='OK'; Error='' }
  } catch {
    Write-Warning "[$C] GPT upload preparation failed: $_"
    $Results += [pscustomobject]@{ Code=$C; Status='ERROR'; Error=[string]$_ }
  }
}

Write-Host ''
Write-Host '============================================================'
Write-Host '[ALL DONE] GPT upload preparation'
foreach ($R in $Results) {
  Write-Host ("  {0}: {1}" -f $R.Code, $R.Status)
  if ($R.Status -ne 'OK') { Write-Host ("      {0}" -f $R.Error) }
}
Write-Host '============================================================'

$FailedResults = @($Results | Where-Object { $_.Status -ne 'OK' })
if ($FailedResults.Count -gt 0) {
  Write-Error ("GPT upload preparation failed for {0} stock(s)." -f $FailedResults.Count)
  exit 1
}
exit 0
