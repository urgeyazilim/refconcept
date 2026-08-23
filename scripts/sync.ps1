<#
.SYNOPSIS
    Push apps/api from the host into the api container's source volume.

.DESCRIPTION
    Application source lives in a named volume rather than a bind mount, because a
    Laravel boot costs ~22.8s over the Windows bind mount versus ~4.3s from the volume
    (see docs/ADR/ADR-0002). This script performs the host -> container copy;
    `docker compose watch` does the same continuously.

    `docker cp` only ever adds or overwrites, so source directories owned entirely by
    the host are cleared first. Without that, a file deleted or renamed on the host
    keeps running inside the container — which is exactly how a replaced migration
    ends up executing twice.

.PARAMETER Pull
    Reverse direction: copy container-generated files back onto the host.
#>
[CmdletBinding()]
param(
    [switch]$Pull
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $RepoRoot

$container = 'refconcept-api'

# Directories whose contents come exclusively from the host and are safe to mirror.
# storage/ (runtime writes) and vendor/ (separate volume) are deliberately absent.
$MirroredDirs = @('app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'tests')

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
        docker cp "${container}:/var/www/html/$entry" 'apps/api/'
        if ($LASTEXITCODE -ne 0) { throw "docker cp failed for '$entry'" }
    }

    # Compiled caches are build artifacts. Left on the host they get pushed back on the
    # next sync and can shadow newly installed packages.
    Remove-Item 'apps/api/bootstrap/cache/*.php' -Force -ErrorAction SilentlyContinue

    Write-Host "Pull complete ($($entries.Count) entries)." -ForegroundColor Green
    return
}

Write-Host "Syncing apps/api -> ${container}:/var/www/html" -ForegroundColor Cyan

# Clear mirrored directories so host-side deletions propagate.
$clearList = ($MirroredDirs | ForEach-Object { "/var/www/html/$_" }) -join ' '
docker compose exec -T api sh -c "rm -rf $clearList"
if ($LASTEXITCODE -ne 0) { throw 'failed to clear mirrored directories' }

docker cp 'apps/api/.' "${container}:/var/www/html"
if ($LASTEXITCODE -ne 0) { throw 'docker cp failed' }

# Writable runtime directories must exist and stay writable for php-fpm.
# bootstrap/cache is cleared, not just created: a stale packages.php copied in from the
# host hides freshly installed packages (this masked Sanctum's auth guard once already).
docker compose exec -T api sh -c 'rm -f bootstrap/cache/*.php && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing storage/logs bootstrap/cache && chmod -R 0777 storage bootstrap/cache'
if ($LASTEXITCODE -ne 0) { throw 'failed to prepare writable directories' }

Write-Host 'Sync complete.' -ForegroundColor Green
