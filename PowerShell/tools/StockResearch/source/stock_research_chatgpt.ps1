# Debug single stock:
# powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\stock_research_chatgpt.ps1" -Code 4183
# First-time ChatGPT login setup:
# powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\stock_research_chatgpt.ps1" -SetupLogin
# Login check only:
# powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\stock_research_chatgpt.ps1" -LoginOnly

param(
    [Parameter(Mandatory = $false)]
    [ValidatePattern('^\d{4}$')]
    [string]$Code,

    [switch]$SetupLogin,
    [switch]$LoginOnly,
    [switch]$RetryErrors
)

# Directory layout
$RootDir = Split-Path -Parent $PSScriptRoot

$ErrorActionPreference = 'Stop'

$ProfileDir = Join-Path -Path $RootDir -ChildPath 'tools\chatgpt_profile'
$EdgeExe = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
$CdpPort = 9333

if (-not (Test-Path -LiteralPath $EdgeExe -PathType Leaf)) {
    throw "Microsoft Edge was not found: $EdgeExe"
}
if (-not (Test-Path -LiteralPath $ProfileDir -PathType Container)) {
    New-Item -ItemType Directory -Path $ProfileDir -Force | Out-Null
}

# -----------------------------------------------------------------------------
# -SetupLogin
# Launch ordinary Edge with the dedicated profile, without Playwright/CDP.
# -----------------------------------------------------------------------------
if ($SetupLogin) {
    if ($Code -or $LoginOnly -or $RetryErrors) {
        throw '-SetupLogin cannot be combined with -Code, -LoginOnly, or -RetryErrors.'
    }

    Write-Host '[SETUP] Starting normal Microsoft Edge without Playwright.'
    Write-Host ("[SETUP] Dedicated profile: {0}" -f $ProfileDir)
    Write-Host '[SETUP] Sign in to ChatGPT manually.'
    Write-Host '[SETUP] When finished, close ALL windows opened by this dedicated Edge profile.'

    $EdgeArgs = @(
        "`"--user-data-dir=$ProfileDir`"",
        '--no-first-run',
        '--no-default-browser-check',
        'https://chatgpt.com/'
    )

    $Process = Start-Process -FilePath $EdgeExe -ArgumentList $EdgeArgs -PassThru
    $Process.WaitForExit()

    Write-Host '[SETUP] Dedicated Edge was closed. Login setup is complete.'
    exit 0
}

$JsPath = Join-Path -Path $PSScriptRoot -ChildPath 'stock_research_chatgpt.js'
$PlaywrightPath = Join-Path -Path $PSScriptRoot -ChildPath 'node_modules\playwright'

$NodeCommand = Get-Command 'node.exe' -ErrorAction SilentlyContinue
if (-not $NodeCommand) { throw 'Node.js was not found. Install Node.js first.' }
if (-not (Test-Path -LiteralPath $JsPath -PathType Leaf)) { throw "JavaScript file was not found: $JsPath" }
if (-not (Test-Path -LiteralPath $PlaywrightPath -PathType Container)) {
    throw "Playwright was not found: $PlaywrightPath. Run npm.cmd install in this folder."
}

# Start ordinary Edge ourselves. Playwright does NOT launch Edge.
Write-Host ("[CDP] Starting dedicated normal Edge on port {0}." -f $CdpPort)
$EdgeArgs = @(
    "`"--user-data-dir=$ProfileDir`"",
    "--remote-debugging-port=$CdpPort",
    '--no-first-run',
    '--no-default-browser-check',
    'https://chatgpt.com/'
)
$EdgeProcess = Start-Process -FilePath $EdgeExe -ArgumentList $EdgeArgs -PassThru

# Wait for the CDP endpoint.
$CdpReady = $false
for ($i = 0; $i -lt 30; $i++) {
    try {
        $null = Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$CdpPort/json/version" -TimeoutSec 2
        $CdpReady = $true
        break
    } catch {
        Start-Sleep -Seconds 1
    }
}
if (-not $CdpReady) {
    throw "Edge started, but CDP port $CdpPort did not become ready."
}

$NodeArgs = @(
    $JsPath,
    '--root', $RootDir,
    '--cdp-url', "http://127.0.0.1:$CdpPort"
)
if ($Code) { $NodeArgs += @('--code', $Code) }
if ($LoginOnly) { $NodeArgs += '--login-only' }
if ($RetryErrors) { $NodeArgs += '--retry-errors' }

& $NodeCommand.Source @NodeArgs
$ExitCode = $LASTEXITCODE

if ($ExitCode -ne 0) {
    throw "ChatGPT automation failed. exit code=$ExitCode"
}
