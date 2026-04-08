#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Database backup script for crø chat application.

.DESCRIPTION
    Creates timestamped backups of the crø database with optional compression.
    Supports full backups, schema-only, and data-only modes.
    Manages backup rotation (keeps last N backups).

.PARAMETER Mode
    Backup mode: full (default), schema, data

.PARAMETER Compress
    Compress backup with gzip (default: true)

.PARAMETER Keep
    Number of backups to keep (default: 30, 0 = unlimited)

.PARAMETER OutputDir
    Backup output directory (default: ./backups)

.EXAMPLE
    .\backup.ps1
    .\backup.ps1 -Mode schema
    .\backup.ps1 -Keep 7 -OutputDir D:\backups
#>

param(
    [ValidateSet('full', 'schema', 'data')]
    [string]$Mode = 'full',

    [bool]$Compress = $true,

    [int]$Keep = 30,

    [string]$OutputDir = (Join-Path $PSScriptRoot '..' 'backups')
)

$ErrorActionPreference = 'Stop'

# ── Config ──
$DbHost = $env:DB_HOST ?? '127.0.0.1'
$DbPort = $env:DB_PORT ?? '3306'
$DbName = $env:DB_NAME ?? 'cro_chat'
$DbUser = $env:DB_USER ?? 'root'
$DbPass = $env:DB_PASS ?? ''
$MysqlDump = $env:MYSQLDUMP_PATH ?? 'C:\xampp\mysql\bin\mysqldump.exe'

# ── Ensure output dir ──
if (-not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}

$timestamp = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$baseName = "cro_chat_${Mode}_${timestamp}"

Write-Host "╔══════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║  crø Database Backup                 ║" -ForegroundColor Cyan
Write-Host "╚══════════════════════════════════════╝" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Mode:     $Mode"
Write-Host "  Database: $DbName @ ${DbHost}:${DbPort}"
Write-Host "  Output:   $OutputDir"
Write-Host ""

# ── Build mysqldump args ──
$dumpArgs = @(
    "--host=$DbHost"
    "--port=$DbPort"
    "--user=$DbUser"
    "--single-transaction"          # Consistent snapshot (InnoDB)
    "--routines"                    # Include stored procedures
    "--triggers"                    # Include triggers
    "--events"                      # Include events
    "--set-gtid-purged=OFF"         # Compatibility
    "--column-statistics=0"         # Suppress warning on newer mysqldump
)

if ($DbPass -ne '') {
    $dumpArgs += "--password=$DbPass"
}

switch ($Mode) {
    'schema' {
        $dumpArgs += '--no-data'
    }
    'data' {
        $dumpArgs += '--no-create-info'
        $dumpArgs += '--complete-insert'
    }
    'full' {
        $dumpArgs += '--complete-insert'
    }
}

$dumpArgs += $DbName

# ── Execute backup ──
$sqlFile = Join-Path $OutputDir "$baseName.sql"

Write-Host "  Dumping database..." -NoNewline
$stopwatch = [System.Diagnostics.Stopwatch]::StartNew()

try {
    & $MysqlDump @dumpArgs | Out-File -FilePath $sqlFile -Encoding UTF8 -Force
    $stopwatch.Stop()
    Write-Host " OK ($('{0:N1}' -f $stopwatch.Elapsed.TotalSeconds)s)" -ForegroundColor Green
}
catch {
    Write-Host " FAILED" -ForegroundColor Red
    Write-Error "mysqldump failed: $_"
    exit 1
}

# ── Compress ──
$finalFile = $sqlFile
if ($Compress) {
    Write-Host "  Compressing..." -NoNewline
    try {
        $gzFile = "$sqlFile.gz"
        $input = [System.IO.File]::OpenRead($sqlFile)
        $output = [System.IO.File]::Create($gzFile)
        $gzip = [System.IO.Compression.GZipStream]::new($output, [System.IO.Compression.CompressionLevel]::Optimal)
        $input.CopyTo($gzip)
        $gzip.Dispose()
        $output.Dispose()
        $input.Dispose()
        Remove-Item $sqlFile -Force
        $finalFile = $gzFile
        Write-Host " OK" -ForegroundColor Green
    }
    catch {
        Write-Host " SKIPPED (compression failed)" -ForegroundColor Yellow
        $finalFile = $sqlFile
    }
}

$sizeBytes = (Get-Item $finalFile).Length
$sizeMB = [math]::Round($sizeBytes / 1MB, 2)

# ── Write metadata ──
$metaFile = Join-Path $OutputDir "$baseName.json"
@{
    database   = $DbName
    host       = $DbHost
    mode       = $Mode
    timestamp  = $timestamp
    file       = (Split-Path $finalFile -Leaf)
    size_bytes = $sizeBytes
    compressed = $Compress
    created_at = (Get-Date -Format 'o')
} | ConvertTo-Json | Out-File -FilePath $metaFile -Encoding UTF8

# ── Rotation ──
if ($Keep -gt 0) {
    $backups = Get-ChildItem -Path $OutputDir -Filter "cro_chat_${Mode}_*" |
    Where-Object { $_.Extension -in '.sql', '.gz' } |
    Sort-Object -Property LastWriteTime -Descending |
    Select-Object -Skip $Keep

    foreach ($old in $backups) {
        $metaPath = $old.FullName -replace '\.(sql|gz)$', '.json'
        Remove-Item $old.FullName -Force -ErrorAction SilentlyContinue
        if (Test-Path $metaPath) {
            Remove-Item $metaPath -Force -ErrorAction SilentlyContinue
        }
        Write-Host "  Rotated: $($old.Name)" -ForegroundColor DarkGray
    }
}

Write-Host ""
Write-Host "  ✓ Backup complete: $(Split-Path $finalFile -Leaf) ($sizeMB MB)" -ForegroundColor Green
Write-Host ""
