# Kill only Chromium processes launched for the whatsapp-web-spike session
# (CommandLine contains .wwebjs_auth). Does NOT kill your normal Chrome tabs.

$ErrorActionPreference = 'SilentlyContinue'
$marker = 'wwebjs_auth'
$killed = 0

Get-CimInstance Win32_Process -Filter "Name = 'chrome.exe' OR Name = 'chromium.exe'" |
  Where-Object { $_.CommandLine -and $_.CommandLine -like "*$marker*" } |
  ForEach-Object {
    Write-Host "[spike] Killing PID $($_.ProcessId) ($($_.Name))"
    Stop-Process -Id $_.ProcessId -Force
    $killed++
  }

# Also clear stale profile locks
$auth = Join-Path $PSScriptRoot '..' '.wwebjs_auth'
$locks = @('SingletonLock', 'SingletonCookie', 'SingletonSocket', 'lockfile')
Get-ChildItem -Path $auth -Recurse -Force -ErrorAction SilentlyContinue |
  Where-Object { $locks -contains $_.Name } |
  ForEach-Object {
    Write-Host "[spike] Removing lock $($_.FullName)"
    Remove-Item $_.FullName -Force -ErrorAction SilentlyContinue
  }

if ($killed -eq 0) {
  Write-Host '[spike] No spike Chrome processes found.'
} else {
  Write-Host "[spike] Killed $killed spike Chrome process(es)."
}
