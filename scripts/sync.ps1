<#
.SYNOPSIS
    Push apps/api from the host into the api container's source volume.

.DESCRIPTION
    Application source lives in a named volume rather than a bind mount, because a
    Laravel boot costs ~22.8s over the Windows bind mount versus ~2.5s on the
    container filesystem (see docs/ADR/ADR-0002). This script performs the one-shot
    host -> container copy; `docker compose watch` does the same continuously.

.PARAMETER Pull
    Reverse direction: copy container-generated files (artisan output, migrations
    created inside the container) back onto the host.
#>
[CmdletBinding()]
param(
    [switch]$Pull
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $RepoRoot

$container = 'refconcept-api'

$running = docker ps --filter "name=$container" --format '{{.Names}}'
if ($running -notcontains $container) {
    throw "Container '$container' is not running. Start it with: .\scripts\rc.ps1 up"
}

if ($Pull) {
    Write-Host "Pulling ${container}:/var/www/html -> apps/api" -ForegroundColor Cyan

    # vendor/ is a separate volume holding thousands of files; copying it onto the
    # Windows filesystem is both pointless and extremely slow, so it is never pulled.
    $entries = (docker compose exec -T api sh -c 'ls -A /var/www/html') -split "`n" |
        ForEach-Object { $_.Trim() } |
        Where-Object { $_ -and $_ -ne 'vendor' }

    foreach ($entry in $entries) {
        docker cp "${container}:/var/www/html/$entry" "apps/api/"
        if ($LASTEXITCODE -ne 0) { throw "docker cp failed for '$entry'" }
    }

    Write-Host "Pull complete ($($entries.Count) entries)." -ForegroundColor Green
    return
}

Write-Host "Syncing apps/api -> ${container}:/var/www/html" -ForegroundColor Cyan
docker cp 'apps/api/.' "${container}:/var/www/html"
if ($LASTEXITCODE -ne 0) { throw 'docker cp failed' }

# Writable runtime directories must stay writable for php-fpm (uid 82 in the alpine image).
docker compose exec -T api sh -c 'mkdir -p storage/framework/{cache/data,sessions,views,testing} storage/logs bootstrap/cache && chmod -R 0777 storage bootstrap/cache'
if ($LASTEXITCODE -ne 0) { throw 'failed to prepare writable directories' }

Write-Host 'Sync complete.' -ForegroundColor Green
