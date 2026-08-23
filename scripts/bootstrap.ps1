<#
.SYNOPSIS
    First-time RefConcept setup on Windows.

.DESCRIPTION
    Idempotent: safe to re-run. Brings up the docker stack, installs backend
    dependencies, generates the app key, runs migrations and seeds, then verifies
    the health endpoint.
#>
[CmdletBinding()]
param(
    [switch]$SkipFrontend
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $RepoRoot

function Write-Step($message) {
    Write-Host "`n==> $message" -ForegroundColor Cyan
}

Write-Step 'Checking Docker'
docker version --format '{{.Server.Version}}' | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'Docker daemon is not reachable. Start Docker Desktop and re-run.'
}

Write-Step 'Preparing environment files'
if (-not (Test-Path '.env')) {
    Copy-Item '.env.example' '.env'
    Write-Host '  created .env'
}
if (-not (Test-Path 'apps/api/.env')) {
    Copy-Item 'apps/api/.env.example' 'apps/api/.env'
    Write-Host '  created apps/api/.env'
}

Write-Step 'Starting infrastructure'
docker compose up -d postgres redis minio mailpit minio-init

Write-Step 'Waiting for PostgreSQL and Redis'
$deadline = (Get-Date).AddMinutes(3)
while ((Get-Date) -lt $deadline) {
    $states = docker compose ps --format '{{.Service}} {{.Health}}'
    if (($states -match 'postgres healthy') -and ($states -match 'redis healthy')) { break }
    Start-Sleep -Seconds 3
}

Write-Step 'Starting API containers'
docker compose up -d api nginx queue scheduler

Write-Step 'Syncing API source into the container'
# Source lives in a named volume for I/O speed (ADR-0002), so it must be pushed in.
& "$PSScriptRoot\sync.ps1"

Write-Step 'Installing PHP dependencies'
docker compose exec -T api composer install --no-interaction --prefer-dist

Write-Step 'Application key'
$envContent = Get-Content 'apps/api/.env' -Raw
if ($envContent -match '(?m)^APP_KEY=\s*$') {
    docker compose exec -T api php artisan key:generate
} else {
    Write-Host '  APP_KEY already set'
}

Write-Step 'Running migrations'
docker compose exec -T api php artisan migrate --force

Write-Step 'Seeding'
docker compose exec -T api php artisan db:seed --force

if (-not $SkipFrontend) {
    Write-Step 'Installing frontend dependencies'
    npm install
}

Write-Step 'Verifying health endpoint'
$port = if ($env:API_PORT_HOST) { $env:API_PORT_HOST } else { '58000' }
$ok = $false
for ($i = 0; $i -lt 20; $i++) {
    try {
        $r = Invoke-WebRequest -Uri "http://localhost:$port/api/health" -UseBasicParsing -TimeoutSec 5
        Write-Host $r.Content
        $ok = $true
        break
    } catch {
        Start-Sleep -Seconds 3
    }
}

if ($ok) {
    Write-Host "`nRefConcept is up.  API http://localhost:$port  Storefront: npm run dev:storefront" -ForegroundColor Green
} else {
    throw "Health endpoint did not respond on port $port. Check: .\scripts\rc.ps1 logs api"
}
