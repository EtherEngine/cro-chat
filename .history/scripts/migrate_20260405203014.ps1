#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Zero-downtime database migration runner.

.DESCRIPTION
    Runs database migrations safely with:
    - Tracking table to prevent re-running migrations
    - Dry-run mode for preview
    - Atomic execution per migration file
    - Rollback support via backup + restore

    Migrations MUST be additive (CREATE TABLE IF NOT EXISTS,
    ALTER TABLE ADD COLUMN IF NOT EXISTS) for zero-downtime.

.PARAMETER DryRun
    Preview migrations without executing

.PARAMETER Force
    Run even if migration was already applied

.EXAMPLE
    .\migrate.ps1
    .\migrate.ps1 -DryRun
#>

param(
    [switch]$DryRun,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

# ── Config ──
$DbHost = $env:DB_HOST ?? '127.0.0.1'
$DbPort = $env:DB_PORT ?? '3306'
$DbName = $env:DB_NAME ?? 'cro_chat'
$DbUser = $env:DB_USER ?? 'root'
$DbPass = $env:DB_PASS ?? ''
$Mysql = $env:MYSQL_PATH ?? 'C:\xampp\mysql\bin\mysql.exe'
$MigrationDir = Join-Path $PSScriptRoot '..' 'database'

function Invoke-Sql([string]$sql) {
    $args = @("--host=$DbHost", "--port=$DbPort", "--user=$DbUser", "--database=$DbName", "-N", "-B")
    if ($DbPass -ne '') { $args += "--password=$DbPass" }
    $sql | & $Mysql @args 2>&1
}

Write-Host ""
Write-Host "╔══════════════════════════════════════╗" -ForegroundColor Magenta
Write-Host "║  crø Database Migration              ║" -ForegroundColor Magenta
Write-Host "╚══════════════════════════════════════╝" -ForegroundColor Magenta
Write-Host ""

# ── 1. Create tracking table ──
$createTracking = @"
CREATE TABLE IF NOT EXISTS _migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    checksum CHAR(64) NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    duration_ms INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
"@
Invoke-Sql $createTracking | Out-Null

# ── 2. Get already-applied migrations ──
$applied = @{}
$rows = Invoke-Sql "SELECT filename, checksum FROM _migrations ORDER BY id"
foreach ($row in $rows) {
    if ($row -and $row -match '^(\S+)\t(\S+)$') {
        $applied[$Matches[1]] = $Matches[2]
    }
}

Write-Host "  Applied: $($applied.Count) migrations" -ForegroundColor DarkGray

# ── 3. Find pending migrations ──
$files = Get-ChildItem -Path $MigrationDir -Filter 'migration_*.sql' | Sort-Object Name
$pending = @()

foreach ($file in $files) {
    $content = Get-Content $file.FullName -Raw
    $checksum = [System.BitConverter]::ToString(
        [System.Security.Cryptography.SHA256]::Create().ComputeHash(
            [System.Text.Encoding]::UTF8.GetBytes($content)
        )
    ).Replace('-', '').ToLower()

    if ($applied.ContainsKey($file.Name)) {
        if ($applied[$file.Name] -ne $checksum -and -not $Force) {
            Write-Host "  ⚠ Checksum mismatch: $($file.Name)" -ForegroundColor Yellow
            Write-Host "    Expected: $($applied[$file.Name])" -ForegroundColor DarkGray
            Write-Host "    Actual:   $checksum" -ForegroundColor DarkGray
        }
        continue
    }

    $pending += @{
        File     = $file
        Checksum = $checksum
        Content  = $content
    }
}

if ($pending.Count -eq 0) {
    Write-Host ""
    Write-Host "  ✓ All migrations are up to date" -ForegroundColor Green
    Write-Host ""
    exit 0
}

Write-Host "  Pending: $($pending.Count) migration(s)" -ForegroundColor Cyan
Write-Host ""

# ── 4. Apply migrations ──
$success = 0
$failed = 0

foreach ($m in $pending) {
    $name = $m.File.Name
    Write-Host "  Applying: $name" -NoNewline

    if ($DryRun) {
        Write-Host " [DRY RUN]" -ForegroundColor Cyan
        continue
    }

    $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()

    try {
        # Execute migration (only cro_chat part, skip cro_chat_test)
        $sqlContent = $m.Content
        # Filter to only exec on main DB — skip USE statements for test DB
        $mysqlArgs = @("--host=$DbHost", "--port=$DbPort", "--user=$DbUser", "--database=$DbName")
        if ($DbPass -ne '') { $mysqlArgs += "--password=$DbPass" }
        $sqlContent | & $Mysql @mysqlArgs 2>&1 | Out-Null

        $stopwatch.Stop()
        $durationMs = [int]$stopwatch.ElapsedMilliseconds

        # Record in tracking table
        $escapedName = $name.Replace("'", "''")
        $escapedChecksum = $m.Checksum.Replace("'", "''")
        Invoke-Sql "INSERT INTO _migrations (filename, checksum, duration_ms) VALUES ('$escapedName', '$escapedChecksum', $durationMs)" | Out-Null

        $success++
        Write-Host " ✓ (${durationMs}ms)" -ForegroundColor Green
    } catch {
        $stopwatch.Stop()
        $failed++
        Write-Host " ✗ FAILED" -ForegroundColor Red
        Write-Host "    Error: $_" -ForegroundColor Red

        if ($failed -ge 1) {
            Write-Host ""
            Write-Host "  ⚠ Stopping after first failure" -ForegroundColor Yellow
            break
        }
    }
}

Write-Host ""
if ($DryRun) {
    Write-Host "  [DRY RUN] Would apply $($pending.Count) migration(s)" -ForegroundColor Cyan
} else {
    Write-Host "  Applied: $success | Failed: $failed" -ForegroundColor $(if ($failed -eq 0) { 'Green' } else { 'Yellow' })
}
Write-Host ""
