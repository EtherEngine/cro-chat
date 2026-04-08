#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Database restore script for crø chat application.

.DESCRIPTION
    Restores a crø database backup from a .sql or .sql.gz file.
    Supports dry-run mode and target database override.

.PARAMETER BackupFile
    Path to the backup file (.sql or .sql.gz)

.PARAMETER TargetDb
    Target database name (default: cro_chat)

.PARAMETER DryRun
    Show what would be done without executing

.PARAMETER Force
    Skip confirmation prompt

.EXAMPLE
    .\restore.ps1 -BackupFile backups\cro_chat_full_2026-04-05_120000.sql.gz
    .\restore.ps1 -BackupFile backup.sql -TargetDb cro_chat_staging -Force
#>

param(
    [Parameter(Mandatory)]
    [string]$BackupFile,

    [string]$TargetDb = '',

    [switch]$DryRun,

    [switch]$Force
)

$ErrorActionPreference = 'Stop'

# ── Config ──
$DbHost = $env:DB_HOST ?? '127.0.0.1'
$DbPort = $env:DB_PORT ?? '3306'
$DbName = if ($TargetDb) { $TargetDb } else { $env:DB_NAME ?? 'cro_chat' }
$DbUser = $env:DB_USER ?? 'root'
$DbPass = $env:DB_PASS ?? ''
$Mysql = $env:MYSQL_PATH ?? 'C:\xampp\mysql\bin\mysql.exe'

# ── Validate ──
if (-not (Test-Path $BackupFile)) {
    Write-Error "Backup file not found: $BackupFile"
    exit 1
}

$fileInfo = Get-Item $BackupFile
$isCompressed = $fileInfo.Extension -eq '.gz'
$sizeMB = [math]::Round($fileInfo.Length / 1MB, 2)

Write-Host "╔══════════════════════════════════════╗" -ForegroundColor Yellow
Write-Host "║  crø Database Restore                ║" -ForegroundColor Yellow
Write-Host "╚══════════════════════════════════════╝" -ForegroundColor Yellow
Write-Host ""
Write-Host "  Source:     $($fileInfo.Name) ($sizeMB MB)"
Write-Host "  Target:     $DbName @ ${DbHost}:${DbPort}"
Write-Host "  Compressed: $isCompressed"
Write-Host ""

# ── Check metadata ──
$metaPath = $BackupFile -replace '\.(sql|sql\.gz)$', '.json'
if (Test-Path $metaPath) {
    $meta = Get-Content $metaPath | ConvertFrom-Json
    Write-Host "  Backup info:" -ForegroundColor Cyan
    Write-Host "    Original DB: $($meta.database)"
    Write-Host "    Mode:        $($meta.mode)"
    Write-Host "    Created:     $($meta.created_at)"
    Write-Host ""
}

if ($DryRun) {
    Write-Host "  [DRY RUN] Would restore $($fileInfo.Name) to $DbName" -ForegroundColor Cyan
    exit 0
}

# ── Confirmation ──
if (-not $Force) {
    Write-Host "  ⚠ WARNING: This will OVERWRITE data in database '$DbName'!" -ForegroundColor Red
    $confirm = Read-Host "  Type 'YES' to proceed"
    if ($confirm -ne 'YES') {
        Write-Host "  Aborted." -ForegroundColor Yellow
        exit 0
    }
}

# ── Build mysql args ──
$mysqlArgs = @(
    "--host=$DbHost"
    "--port=$DbPort"
    "--user=$DbUser"
    "--database=$DbName"
)

if ($DbPass -ne '') {
    $mysqlArgs += "--password=$DbPass"
}

# ── Execute restore ──
Write-Host ""
Write-Host "  Restoring..." -NoNewline
$stopwatch = [System.Diagnostics.Stopwatch]::StartNew()

try {
    if ($isCompressed) {
        # Decompress and pipe to mysql
        $input = [System.IO.File]::OpenRead($BackupFile)
        $gzip = [System.IO.Compression.GZipStream]::new($input, [System.IO.Compression.CompressionMode]::Decompress)
        $reader = [System.IO.StreamReader]::new($gzip)
        $sqlContent = $reader.ReadToEnd()
        $reader.Dispose()
        $gzip.Dispose()
        $input.Dispose()

        $sqlContent | & $Mysql @mysqlArgs
    } else {
        Get-Content $BackupFile -Raw | & $Mysql @mysqlArgs
    }

    $stopwatch.Stop()
    Write-Host " OK ($('{0:N1}' -f $stopwatch.Elapsed.TotalSeconds)s)" -ForegroundColor Green
} catch {
    $stopwatch.Stop()
    Write-Host " FAILED" -ForegroundColor Red
    Write-Error "Restore failed: $_"
    exit 1
}

Write-Host ""
Write-Host "  ✓ Restore complete to '$DbName'" -ForegroundColor Green
Write-Host ""
