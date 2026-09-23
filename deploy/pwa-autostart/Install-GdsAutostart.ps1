<#
.SYNOPSIS
  Add (or remove) the GDS PWA to Windows startup, so it launches at sign-in.

.DESCRIPTION
  A web app cannot register itself for OS startup (browsers forbid it), so
  this is done at the OS level. This script creates a shortcut in the current
  user's Startup folder that opens GDS in an app-style (chromeless) window at
  login. No admin rights required. Run it once per user account on each
  machine after the app has been installed.

  For a centrally managed fleet, prefer the enterprise policy instead — see
  the .reg templates in .\policies\ and README.md.

.PARAMETER AppUrl
  The GDS URL to open. Use your PRODUCTION url, e.g. https://gds.belimpex.ng/
  Default: https://gds.test/?source=pwa (development only).

.PARAMETER Browser
  auto (default) | chrome | edge. 'auto' prefers Chrome, then Edge.

.PARAMETER Uninstall
  Remove the startup shortcut instead of creating it.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\Install-GdsAutostart.ps1 -AppUrl "https://gds.belimpex.ng/"

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\Install-GdsAutostart.ps1 -Uninstall
#>
param(
    [string]$AppUrl = "https://gds.test/?source=pwa",
    [ValidateSet('auto', 'chrome', 'edge')][string]$Browser = 'auto',
    [switch]$Uninstall
)

$ErrorActionPreference = 'Stop'
$startup  = [Environment]::GetFolderPath('Startup')
$linkPath = Join-Path $startup 'GDS.lnk'

if ($Uninstall) {
    if (Test-Path $linkPath) { Remove-Item $linkPath -Force; Write-Host "Removed startup entry: $linkPath" }
    else { Write-Host "No GDS startup entry found." }
    return
}

function Find-Browser([string]$which) {
    $chrome = @(
        "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
        "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
        "$env:LocalAppData\Google\Chrome\Application\chrome.exe"
    )
    $edge = @(
        "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
        "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
    )
    switch ($which) {
        'chrome' { $paths = $chrome }
        'edge'   { $paths = $edge }
        default  { $paths = $chrome + $edge }
    }
    foreach ($p in $paths) { if (Test-Path $p) { return $p } }
    return $null
}

$exe = Find-Browser $Browser
if (-not $exe) { throw "No supported browser (Chrome/Edge) found. Install one, or pass -Browser." }

$ws = New-Object -ComObject WScript.Shell
$sc = $ws.CreateShortcut($linkPath)
$sc.TargetPath       = $exe
$sc.Arguments        = "--app=$AppUrl"
$sc.WorkingDirectory = Split-Path $exe
$sc.IconLocation     = "$exe,0"
$sc.Description       = "Global Data System"
$sc.Save()

Write-Host "GDS will now start at sign-in for this user."
Write-Host "  Shortcut : $linkPath"
Write-Host "  Browser  : $exe"
Write-Host "  URL      : $AppUrl"
Write-Host "Remove it later with:  .\Install-GdsAutostart.ps1 -Uninstall"
