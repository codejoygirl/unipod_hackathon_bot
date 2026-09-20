# Seed demo user + community + published knowledge, then print ask examples.
# Run from backend/ with Sail up (and AI service on :8001 for real RAG answers).

param(
    [string]$Email = "demo@zak.test",
    [string]$Password = "password123",
    [switch]$SkipAi
)

$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot\..

$skip = if ($SkipAi) { "--skip-ai" } else { "" }

Write-Host "Seeding via Sail..." -ForegroundColor Cyan
.\vendor\bin\sail artisan zak:seed-assistant-demo --email=$Email --password=$Password $skip

if (Test-Path .\storage\app\zak-demo-ask.env) {
    Write-Host ""
    Write-Host "Loaded vars from storage/app/zak-demo-ask.env" -ForegroundColor Green
    Get-Content .\storage\app\zak-demo-ask.env | ForEach-Object {
        if ($_ -match '^(ZAK_[A-Z_]+)=(.*)$') {
            Set-Item -Path "Env:$($matches[1])" -Value $matches[2]
        }
    }

    Write-Host ""
    Write-Host "Quick ask:" -ForegroundColor Cyan
    $body = @{
        query           = "When does the clinic open?"
        community_ids   = @($env:ZAK_COMMUNITY_ID)
        target_language = "en"
    } | ConvertTo-Json

    Invoke-RestMethod -Method POST -Uri "$($env:ZAK_API_BASE)/api/v1/assistant/ask" `
        -Headers @{ Authorization = "Bearer $($env:ZAK_DEMO_TOKEN)"; Accept = "application/json" } `
        -ContentType "application/json" `
        -Body $body | ConvertTo-Json -Depth 8
}
