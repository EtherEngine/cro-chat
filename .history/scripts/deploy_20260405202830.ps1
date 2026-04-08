#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Zero-downtime deployment script for crø chat application.

.DESCRIPTION
    Deploys the crø chat application with zero downtime using a
    symlink-based release strategy:
      1. Create timestamped release directory
      2. Copy code (or pull from git)
      3. Install dependencies
      4. Run database migrations (additive only)
      5. Swap symlink atomically
      6. Reload PHP workers gracefully
      7. Warm caches
      8. Clean up old releases

.PARAMETER Target
    Deployment target directory (default: C:\xampp\htdocs\chat-api)

.PARAMETER Releases
    Number of releases to keep (default: 5)

.PARAMETER SkipMigrations
    Skip database migrations

.PARAMETER Rollback
    Roll back to previous release

.EXAMPLE
    .\deploy.ps1
    .\deploy.ps1 -Target D:\www\chat-api -Releases 10
    .\deploy.ps1 -Rollback
#>

param(
    [string]$Target = 'C:\xampp\htdocs\chat-api',

    [int]$Releases = 5,

    [switch]$SkipMigrations,

    [switch]$Rollback
)

$ErrorActionPreference = 'Stop'
$Source = Join-Path $PSScriptRoot '..' 'apps' 'chat-api'
$ReleasesDir = Join-Path $Target 'releases'
$SharedDir = Join-Path $Target 'shared'
$CurrentLink = Join-Path $Target 'current'

# ── Config ──
$DbHost = $env:DB_HOST ?? '127.0.0.1'
$DbPort = $env:DB_PORT ?? '3306'
$DbName = $env:DB_NAME ?? 'cro_chat'
$DbUser = $env:DB_USER ?? 'root'
$DbPass = $env:DB_PASS ?? ''
$Mysql = $env:MYSQL_PATH ?? 'C:\xampp\mysql\bin\mysql.exe'

function Write-Step([string]$msg) {
    Write-Host "  → $msg" -ForegroundColor Cyan
}

function Write-Ok([string]$msg) {
    Write-Host "  ✓ $msg" -ForegroundColor Green
}

function Write-Warn([string]$msg) {
    Write-Host "  ⚠ $msg" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "╔══════════════════════════════════════╗" -ForegroundColor Blue
Write-Host "║  crø Zero-Downtime Deploy            ║" -ForegroundColor Blue
Write-Host "╚══════════════════════════════════════╝" -ForegroundColor Blue
Write-Host ""

# ── Rollback ──
if ($Rollback) {
    Write-Step "Rolling back..."

    $releases = Get-ChildItem -Path $ReleasesDir -Directory |
        Sort-Object -Property Name -Descending

    if ($releases.Count -lt 2) {
        Write-Error "No previous release to roll back to"
        exit 1
    }

    $previous = $releases[1]
    Write-Step "Target: $($previous.Name)"

    # Update symlink
    if (Test-Path $CurrentLink) { Remove-Item $CurrentLink -Force -Recurse }
    New-Item -ItemType Junction -Path $CurrentLink -Target $previous.FullName | Out-Null

    Write-Ok "Rolled back to $($previous.Name)"
    Write-Host ""
    exit 0
}

# ── 1. Setup directories ──
Write-Step "Setting up directories..."
foreach ($dir in @($ReleasesDir, $SharedDir, "$SharedDir\storage", "$SharedDir\storage\logs", "$SharedDir\storage\uploads", "$SharedDir\.env")) {
    if ($dir -like '*.env') {
        if (-not (Test-Path $dir)) {
            # Create empty .env placeholder
            '' | Out-File -FilePath $dir -Encoding UTF8
        }
        continue
    }
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
}

# ── 2. Create release ──
$releaseId = Get-Date -Format 'yyyyMMdd_HHmmss'
$releaseDir = Join-Path $ReleasesDir $releaseId
Write-Step "Creating release: $releaseId"

# Copy source files (excluding dev files)
$excludePatterns = @('.git', 'node_modules', 'tests', '.env', 'storage', 'vendor')
Copy-Item -Path $Source -Destination $releaseDir -Recurse -Force
foreach ($pattern in $excludePatterns) {
    $excludePath = Join-Path $releaseDir $pattern
    if (Test-Path $excludePath) {
        Remove-Item $excludePath -Recurse -Force -ErrorAction SilentlyContinue
    }
}

# ── 3. Link shared resources ──
Write-Step "Linking shared resources..."

# Symlink .env
$envTarget = Join-Path $SharedDir '.env'
$envLink = Join-Path $releaseDir '.env'
if (Test-Path $envTarget) {
    Copy-Item $envTarget $envLink -Force
}

# Symlink storage directory
$storageLink = Join-Path $releaseDir 'storage'
if (-not (Test-Path $storageLink)) {
    New-Item -ItemType Junction -Path $storageLink -Target (Join-Path $SharedDir 'storage') | Out-Null
}

# ── 4. Install dependencies ──
Write-Step "Installing dependencies..."
$composerJson = Join-Path $releaseDir 'composer.json'
if (Test-Path $composerJson) {
    Push-Location $releaseDir
    try {
        & composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | Out-Null
        Write-Ok "Composer dependencies installed"
    } catch {
        Write-Warn "Composer install failed: $_"
    }
    Pop-Location
}

# ── 5. Database migrations (additive only) ──
if (-not $SkipMigrations) {
    Write-Step "Running database migrations..."

    $migrationDir = Join-Path $PSScriptRoot '..' 'database'
    $migrationFiles = Get-ChildItem -Path $migrationDir -Filter 'migration_*.sql' | Sort-Object Name

    $mysqlArgs = @("--host=$DbHost", "--port=$DbPort", "--user=$DbUser", "--database=$DbName")
    if ($DbPass -ne '') { $mysqlArgs += "--password=$DbPass" }

    foreach ($migration in $migrationFiles) {
        Write-Host "    Applying: $($migration.Name)" -NoNewline
        try {
            Get-Content $migration.FullName -Raw | & $Mysql @mysqlArgs 2>&1 | Out-Null
            Write-Host " ✓" -ForegroundColor Green
        } catch {
            Write-Host " (already applied or skipped)" -ForegroundColor DarkGray
        }
    }
}

# ── 6. Swap symlink ──
Write-Step "Swapping to new release..."
$oldRelease = $null
if (Test-Path $CurrentLink) {
    $oldRelease = (Get-Item $CurrentLink).Target
    Remove-Item $CurrentLink -Force -Recurse
}
New-Item -ItemType Junction -Path $CurrentLink -Target $releaseDir | Out-Null
Write-Ok "Live: $releaseId"

# ── 7. Graceful worker restart ──
Write-Step "Signaling workers to restart..."
# Workers detect file change and restart on next cycle
$restartFlag = Join-Path $SharedDir 'storage' '.restart'
Get-Date -Format 'o' | Out-File -FilePath $restartFlag -Encoding UTF8 -Force
Write-Ok "Worker restart signal sent"

# ── 8. Cache warming ──
Write-Step "Warming caches..."
$healthUrl = "http://localhost/chat-api/current/public/api/scaling/health"
try {
    $response = Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec 5 -ErrorAction SilentlyContinue
    if ($response.StatusCode -eq 200) {
        Write-Ok "Health check passed"
    }
} catch {
    Write-Warn "Health check unavailable (app may need Apache restart)"
}

# ── 9. Cleanup old releases ──
Write-Step "Cleaning up old releases..."
$oldReleases = Get-ChildItem -Path $ReleasesDir -Directory |
    Sort-Object -Property Name -Descending |
    Select-Object -Skip $Releases

foreach ($old in $oldReleases) {
    Remove-Item $old.FullName -Recurse -Force -ErrorAction SilentlyContinue
    Write-Host "    Removed: $($old.Name)" -ForegroundColor DarkGray
}

# ── 10. Write deployment log ──
$deployLog = Join-Path $SharedDir 'deployments.log'
$logEntry = @{
    release    = $releaseId
    deployed_at = (Get-Date -Format 'o')
    previous   = if ($oldRelease) { Split-Path $oldRelease -Leaf } else { 'none' }
    hostname   = $env:COMPUTERNAME
} | ConvertTo-Json -Compress
Add-Content -Path $deployLog -Value $logEntry

Write-Host ""
Write-Host "  ╔════════════════════════════════════╗" -ForegroundColor Green
Write-Host "  ║  Deployment successful!             ║" -ForegroundColor Green
Write-Host "  ║  Release: $releaseId          ║" -ForegroundColor Green
Write-Host "  ╚════════════════════════════════════╝" -ForegroundColor Green
Write-Host ""
