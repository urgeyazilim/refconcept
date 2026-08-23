<#
.SYNOPSIS
    RefConcept developer command wrapper (Windows / PowerShell).

.DESCRIPTION
    One entry point for the split topology described in docs/ADR/ADR-0002:
    backend + stateful services in Docker, Nuxt dev servers on the host.

.EXAMPLE
    .\scripts\rc.ps1 up
    .\scripts\rc.ps1 artisan migrate --seed
    .\scripts\rc.ps1 test
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [string]$Command = 'help',

    [Parameter(Position = 1, ValueFromRemainingArguments = $true)]
    [string[]]$Rest
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $RepoRoot

function Invoke-Compose { docker compose @args }
function Invoke-Api { docker compose exec -T api @args }

function Show-Help {
    @'
RefConcept dev commands

  up            start the docker stack and sync the API source into it
  down          stop the stack
  restart       restart the stack
  status        service state + health endpoint probe
  sync          push apps/api from the host into the api container (required after edits)
  pull          copy container-generated files back to apps/api
  watch         continuous host -> container sync (docker compose watch)
  logs [svc]    follow logs (all services, or one)
  shell         bash shell inside the api container
  artisan ...   run an artisan command inside the api container
  composer ...  run composer inside the api container
  migrate       run migrations
  fresh         drop, re-migrate and seed the database
  test          backend test suite (Pest) + static analysis
  bootstrap     first-time setup: env files, keys, deps, migrations
  web           install node dependencies for all frontend workspaces
  dev           start every Nuxt dev server on the host

Ports: api http://localhost:58000  minio http://localhost:59001  mail http://localhost:58025
'@ | Write-Host
}

switch ($Command.ToLower()) {
    'up' {
        Invoke-Compose up -d
        # Source lives in a named volume (ADR-0002); without this the container runs stale code.
        & "$PSScriptRoot\sync.ps1"
        Write-Host "`nStack starting. Run '.\scripts\rc.ps1 status' in a few seconds." -ForegroundColor Green
    }
    'sync'    { & "$PSScriptRoot\sync.ps1" }
    'pull'    { & "$PSScriptRoot\sync.ps1" -Pull }
    'watch'   { Invoke-Compose watch }
    'down'    { Invoke-Compose down }
    'restart' { Invoke-Compose restart }
    'logs'    { if ($Rest) { Invoke-Compose logs -f @Rest } else { Invoke-Compose logs -f } }
    'shell'   { docker compose exec api bash }
    'artisan' { Invoke-Api php artisan @Rest }
    'composer'{ Invoke-Api composer @Rest }
    'migrate' { Invoke-Api php artisan migrate }
    'fresh'   { Invoke-Api php artisan migrate:fresh --seed }
    'test' {
        Invoke-Api php artisan test
        Invoke-Api ./vendor/bin/phpstan analyse --memory-limit=1G
    }
    'status' {
        Invoke-Compose ps
        Write-Host "`nHealth probe:" -ForegroundColor Cyan
        try {
            $port = if ($env:API_PORT_HOST) { $env:API_PORT_HOST } else { '58000' }
            $r = Invoke-WebRequest -Uri "http://localhost:$port/api/health" -UseBasicParsing -TimeoutSec 10
            Write-Host "  HTTP $($r.StatusCode)" -ForegroundColor Green
            Write-Host "  $($r.Content)"
        } catch {
            Write-Host "  unreachable: $($_.Exception.Message)" -ForegroundColor Yellow
        }
    }
    'bootstrap' { & "$PSScriptRoot\bootstrap.ps1" @Rest }
    'web'       { npm install }
    'dev'       { npm run dev:storefront }
    default     { Show-Help }
}
