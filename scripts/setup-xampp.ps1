param(
    [string]$SourceApiPath = "..\apps\chat-api",
    [string]$TargetPath = "C:\xampp\htdocs\chat-api"
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path -LiteralPath $SourceApiPath)) {
    throw "Quelle nicht gefunden: $SourceApiPath"
}

if (-not (Test-Path -LiteralPath $TargetPath)) {
    New-Item -ItemType Directory -Path $TargetPath -Force | Out-Null
}

Copy-Item -Path (Join-Path $SourceApiPath "*") -Destination $TargetPath -Recurse -Force
Write-Host "chat-api nach XAMPP kopiert: $TargetPath"
