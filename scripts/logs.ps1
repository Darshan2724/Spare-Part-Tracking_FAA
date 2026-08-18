# ==============================================================================
# FAITH AUTOMATION — Live Container Log Viewer
# Usage: .\scripts\logs.ps1 [-Service <app|nginx|postgres|reverb|worker|adminer|all>]
# ==============================================================================

param (
    [string]$Service = "all"
)

$serviceMap = @{
    "app"      = "sparetrack-app"
    "nginx"    = "sparetrack-nginx"
    "postgres" = "sparetrack-postgres"
    "redis"    = "sparetrack-redis"
    "reverb"   = "sparetrack-reverb"
    "worker"   = "sparetrack-worker"
    "adminer"  = "sparetrack-adminer"
}

if ($Service -eq "all" -or -not $Service) {
    Write-Host "Streaming logs for all SpareTrack containers (Press Ctrl+C to exit)..." -ForegroundColor Cyan
    docker compose logs -f --tail=50
} else {
    $containerName = $serviceMap[$Service.ToLower()]
    if (-not $containerName) {
        $containerName = $Service
    }
    Write-Host "Streaming logs for container '$containerName' (Press Ctrl+C to exit)..." -ForegroundColor Cyan
    docker logs -f --tail=100 $containerName
}
