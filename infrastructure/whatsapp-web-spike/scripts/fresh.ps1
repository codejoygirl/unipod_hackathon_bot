# Full reset: kill spike Chrome, wipe LocalAuth session, start with fresh QR.
$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $root

Write-Host '[spike] Killing leftover spike Chrome…'
& powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'kill-spike-chrome.ps1')

$auth = Join-Path $root '.wwebjs_auth'
if (Test-Path $auth) {
  Write-Host '[spike] Removing .wwebjs_auth (you will scan QR again)…'
  Remove-Item -Recurse -Force $auth
}

Write-Host '[spike] Starting…'
npm start
