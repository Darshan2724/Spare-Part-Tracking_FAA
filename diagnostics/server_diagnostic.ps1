#Requires -Version 5.1
<#
╔══════════════════════════════════════════════════════════════════════════════╗
║           SPARETRACK SERVER PERFORMANCE DIAGNOSTIC SCRIPT                   ║
║                                                                              ║
║  ██████╗ ███████╗ █████╗ ██████╗       ██████╗ ███╗   ██╗██╗  ██╗   ██╗    ║
║  ██╔══██╗██╔════╝██╔══██╗██╔══██╗     ██╔═══██╗████╗  ██║██║  ╚██╗ ██╔╝    ║
║  ██████╔╝█████╗  ███████║██║  ██║     ██║   ██║██╔██╗ ██║██║   ╚████╔╝     ║
║  ██╔══██╗██╔══╝  ██╔══██║██║  ██║     ██║   ██║██║╚██╗██║██║    ╚██╔╝      ║
║  ██║  ██║███████╗██║  ██║██████╔╝     ╚██████╔╝██║ ╚████║███████╗██║       ║
║  ╚═╝  ╚═╝╚══════╝╚═╝  ╚═╝╚═════╝      ╚═════╝ ╚═╝  ╚═══╝╚══════╝╚═╝       ║
║                                                                              ║
║  SAFETY GUARANTEE:                                                           ║
║  ✅ This script is 100% READ-ONLY                                           ║
║  ✅ It does NOT delete any data                                              ║
║  ✅ It does NOT modify any database records                                  ║
║  ✅ It does NOT stop or restart any Docker containers                        ║
║  ✅ It does NOT stop or restart any Windows services                         ║
║  ✅ It does NOT modify any files on the server                               ║
║  ✅ It does NOT install any software                                         ║
║  ✅ It does NOT change any firewall or network settings                      ║
║  ✅ It does NOT change any registry values                                   ║
║  ✅ It does NOT run any SQL that modifies data (no INSERT/UPDATE/DELETE)     ║
║  ✅ The application continues running normally during this script            ║
║                                                                              ║
║  All output is written to a timestamped log file in the current directory.   ║
║  Share this log file back for analysis.                                      ║
╚══════════════════════════════════════════════════════════════════════════════╝
#>

# ============================================================================
# OUTPUT SETUP
# ============================================================================
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$logFile = "sparetrack_diagnostic_$timestamp.txt"
$startTime = Get-Date

function Log {
    param([string]$message)
    $ts = Get-Date -Format "HH:mm:ss"
    $line = "[$ts] $message"
    Write-Host $line
    Add-Content -Path $logFile -Value $line
}

function LogSection {
    param([string]$title)
    $separator = "=" * 80
    Log ""
    Log $separator
    Log "  $title"
    Log $separator
}

function SafeRun {
    param(
        [string]$label,
        [scriptblock]$cmd
    )
    Log ""
    Log "--- $label ---"
    try {
        $output = & $cmd 2>&1 | Out-String
        if ($output.Trim()) {
            Add-Content -Path $logFile -Value $output.Trim()
            # Only print first few lines to console to avoid flooding
            $lines = $output.Trim() -split "`n"
            $preview = ($lines | Select-Object -First 10) -join "`n"
            Write-Host $preview
            if ($lines.Count -gt 10) { Write-Host "  ... ($($lines.Count) total lines, see log file)" }
        } else {
            Log "  (no output)"
        }
    } catch {
        Log "  ERROR: $($_.Exception.Message)"
    }
}

# ============================================================================
# START
# ============================================================================
Log "╔══════════════════════════════════════════════════════════════════╗"
Log "║  SPARETRACK SERVER PERFORMANCE DIAGNOSTIC                       ║"
Log "║  Started: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')                             ║"
Log "║  Output:  $logFile                              ║"
Log "║  Mode:    READ-ONLY (zero modifications)                        ║"
Log "╚══════════════════════════════════════════════════════════════════╝"

# ============================================================================
# SECTION 1: HARDWARE & SYSTEM INFORMATION
# ============================================================================
LogSection "SECTION 1: HARDWARE & SYSTEM INFORMATION"

SafeRun "CPU Information" {
    Get-CimInstance Win32_Processor | Format-List Name, NumberOfCores, NumberOfLogicalProcessors, MaxClockSpeed, CurrentClockSpeed, LoadPercentage
}

SafeRun "Operating System" {
    Get-CimInstance Win32_OperatingSystem | Format-List Caption, Version, BuildNumber, OSArchitecture, 
        @{N='TotalRAM_GB';E={[math]::Round($_.TotalVisibleMemorySize/1MB,2)}},
        @{N='FreeRAM_GB';E={[math]::Round($_.FreePhysicalMemory/1MB,2)}},
        @{N='UsedRAM_GB';E={[math]::Round(($_.TotalVisibleMemorySize - $_.FreePhysicalMemory)/1MB,2)}},
        @{N='RAM_Usage_Percent';E={[math]::Round((($_.TotalVisibleMemorySize - $_.FreePhysicalMemory)/$_.TotalVisibleMemorySize)*100,1)}}
}

SafeRun "Physical Disks (SSD vs HDD detection)" {
    if (Get-Command Get-PhysicalDisk -ErrorAction SilentlyContinue) {
        Get-PhysicalDisk | Format-Table DeviceId, FriendlyName, MediaType, BusType, Size, HealthStatus -AutoSize
    } else {
        Log "  Get-PhysicalDisk not available, using Get-Disk"
        Get-Disk | Format-Table Number, FriendlyName, Size, PartitionStyle, OperationalStatus -AutoSize
    }
}

SafeRun "Volume Free Space" {
    Get-Volume | Where-Object { $_.DriveLetter } | Format-Table DriveLetter, FileSystemLabel, FileSystem,
        @{N='Size_GB';E={[math]::Round($_.Size/1GB,2)}},
        @{N='Free_GB';E={[math]::Round($_.SizeRemaining/1GB,2)}},
        @{N='Used_Pct';E={if($_.Size -gt 0){[math]::Round((($_.Size-$_.SizeRemaining)/$_.Size)*100,1)}else{0}}} -AutoSize
}

SafeRun "System Uptime" {
    $os = Get-CimInstance Win32_OperatingSystem
    $uptime = (Get-Date) - $os.LastBootUpTime
    "Last Boot: $($os.LastBootUpTime)"
    "Uptime: $($uptime.Days) days, $($uptime.Hours) hours, $($uptime.Minutes) minutes"
}

# ============================================================================
# SECTION 2: LIVE PERFORMANCE COUNTERS (5-second snapshot)
# ============================================================================
LogSection "SECTION 2: LIVE PERFORMANCE COUNTERS"

Log "Capturing 5-second performance snapshot..."

SafeRun "CPU Utilization (5 samples, 1s apart)" {
    $samples = @()
    for ($i = 0; $i -lt 5; $i++) {
        try {
            $counter = Get-Counter '\Processor(_Total)\% Processor Time' -ErrorAction Stop
            $val = [math]::Round($counter.CounterSamples[0].CookedValue, 1)
            $samples += $val
            "  Sample $($i+1): $val%"
        } catch {
            "  Sample $($i+1): ERROR - $($_.Exception.Message)"
        }
        if ($i -lt 4) { Start-Sleep -Seconds 1 }
    }
    if ($samples.Count -gt 0) {
        $avg = [math]::Round(($samples | Measure-Object -Average).Average, 1)
        $max = [math]::Round(($samples | Measure-Object -Maximum).Maximum, 1)
        "  Average CPU: $avg%   Max CPU: $max%"
    }
}

SafeRun "Memory Counters" {
    try {
        $availMB = (Get-Counter '\Memory\Available MBytes' -ErrorAction Stop).CounterSamples[0].CookedValue
        $pagesSec = (Get-Counter '\Memory\Pages/sec' -ErrorAction Stop).CounterSamples[0].CookedValue
        "  Available Memory: $([math]::Round($availMB/1024,2)) GB ($([math]::Round($availMB,0)) MB)"
        "  Pages/sec (paging activity): $([math]::Round($pagesSec,0))"
        if ($pagesSec -gt 100) {
            "  ⚠ HIGH PAGING ACTIVITY - possible memory pressure"
        } else {
            "  ✅ Paging activity normal"
        }
    } catch {
        "  ERROR reading memory counters: $($_.Exception.Message)"
    }
}

SafeRun "Disk Counters" {
    try {
        $readLatency = (Get-Counter '\PhysicalDisk(_Total)\Avg. Disk sec/Read' -ErrorAction Stop).CounterSamples[0].CookedValue
        $writeLatency = (Get-Counter '\PhysicalDisk(_Total)\Avg. Disk sec/Write' -ErrorAction Stop).CounterSamples[0].CookedValue
        $diskTime = (Get-Counter '\PhysicalDisk(_Total)\% Disk Time' -ErrorAction Stop).CounterSamples[0].CookedValue
        $readMs = [math]::Round($readLatency * 1000, 2)
        $writeMs = [math]::Round($writeLatency * 1000, 2)
        $diskPct = [math]::Round($diskTime, 1)
        "  Avg Read Latency:  $readMs ms"
        "  Avg Write Latency: $writeMs ms"
        "  Disk Utilization:  $diskPct%"
        if ($readMs -gt 20) { "  ⚠ HIGH READ LATENCY (>20ms) - possible HDD or I/O saturation" }
        elseif ($readMs -gt 5) { "  ⚡ Moderate read latency (5-20ms)" }
        else { "  ✅ Good read latency (<5ms, likely SSD)" }
    } catch {
        "  ERROR reading disk counters: $($_.Exception.Message)"
    }
}

# ============================================================================
# SECTION 3: DOCKER CONTAINER DIAGNOSTICS
# ============================================================================
LogSection "SECTION 3: DOCKER CONTAINER DIAGNOSTICS"

SafeRun "Docker Version" {
    docker version 2>&1
}

SafeRun "Docker Info (Storage Driver, Runtime)" {
    docker info --format "Storage Driver: {{.Driver}}`nDocker Root Dir: {{.DockerRootDir}}`nTotal Memory: {{.MemTotal}}`nCPUs: {{.NCPU}}`nRunning Containers: {{.ContainersRunning}}`nPaused Containers: {{.ContainersPaused}}`nStopped Containers: {{.ContainersStopped}}" 2>&1
}

SafeRun "Docker Container Status" {
    docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" 2>&1
}

SafeRun "Docker Container Resource Usage (live snapshot)" {
    docker stats --no-stream --format "table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.NetIO}}\t{{.BlockIO}}" 2>&1
}

SafeRun "Docker System Disk Usage" {
    docker system df 2>&1
}

SafeRun "Docker Volume List" {
    docker volume ls 2>&1
}

SafeRun "PostgreSQL Container Inspect (memory/cpu limits)" {
    $inspect = docker inspect sparetrack-postgres --format "Memory Limit: {{.HostConfig.Memory}} bytes`nMemory Reservation: {{.HostConfig.MemoryReservation}} bytes`nCPU Shares: {{.HostConfig.CpuShares}}`nCPU Quota: {{.HostConfig.CpuQuota}}`nRestart Count: {{.RestartCount}}`nHealth: {{if .State.Health}}{{.State.Health.Status}}{{else}}no healthcheck{{end}}" 2>&1
    $inspect
    if ($inspect -match "Memory Limit: 0 bytes") {
        "  → No memory limit set (container can use all host RAM)"
    }
}

SafeRun "App Container Inspect (memory/cpu limits)" {
    docker inspect sparetrack-app --format "Memory Limit: {{.HostConfig.Memory}} bytes`nMemory Reservation: {{.HostConfig.MemoryReservation}} bytes`nCPU Shares: {{.HostConfig.CpuShares}}`nCPU Quota: {{.HostConfig.CpuQuota}}`nRestart Count: {{.RestartCount}}" 2>&1
}

SafeRun "PostgreSQL Volume Location" {
    docker volume inspect postgres_data --format "Mountpoint: {{.Mountpoint}}`nDriver: {{.Driver}}`nCreated: {{.CreatedAt}}" 2>&1
}

SafeRun "Recent App Container Logs (last 30 lines)" {
    docker logs sparetrack-app --tail 30 --timestamps 2>&1
}

SafeRun "Recent PostgreSQL Container Logs (last 30 lines)" {
    docker logs sparetrack-postgres --tail 30 --timestamps 2>&1
}

SafeRun "Recent Nginx Container Logs (last 20 lines)" {
    docker logs sparetrack-nginx --tail 20 --timestamps 2>&1
}

# ============================================================================
# SECTION 4: NETWORK INFORMATION
# ============================================================================
LogSection "SECTION 4: NETWORK INFORMATION"

SafeRun "Network Adapters (Active)" {
    Get-NetAdapter | Where-Object Status -eq 'Up' | Format-Table Name, InterfaceDescription, LinkSpeed, MediaType, MacAddress -AutoSize
}

SafeRun "IP Configuration" {
    Get-NetIPConfiguration | Where-Object { $_.IPv4Address } | Format-List InterfaceAlias, IPv4Address, IPv4DefaultGateway, DNSServer
}

SafeRun "Loopback Latency Test (server to itself)" {
    $results = Test-Connection -ComputerName 127.0.0.1 -Count 5 -ErrorAction SilentlyContinue
    if ($results) {
        $avg = [math]::Round(($results | Measure-Object -Property ResponseTime -Average).Average, 1)
        $max = [math]::Round(($results | Measure-Object -Property ResponseTime -Maximum).Maximum, 1)
        "  Avg: ${avg}ms  Max: ${max}ms (should be <1ms)"
    } else {
        "  Loopback test completed (results may vary by PS version)"
    }
}

# ============================================================================
# SECTION 5: TOP RESOURCE CONSUMERS
# ============================================================================
LogSection "SECTION 5: TOP RESOURCE CONSUMERS"

SafeRun "Top 20 Processes by Memory" {
    Get-Process | Sort-Object WorkingSet64 -Descending | Select-Object -First 20 Name, Id,
        @{N='Memory_MB';E={[math]::Round($_.WorkingSet64/1MB,1)}},
        @{N='CPU_Seconds';E={[math]::Round($_.CPU,1)}} |
        Format-Table -AutoSize
}

# ============================================================================
# SECTION 6: POSTGRESQL DATABASE DIAGNOSTICS (READ-ONLY SQL)
# ============================================================================
LogSection "SECTION 6: POSTGRESQL DATABASE DIAGNOSTICS"

Log "Running read-only SQL diagnostics via docker exec..."
Log "(All queries are SELECT-only. Zero data modifications.)"

# Helper function to run a read-only SQL query
function RunSQL {
    param(
        [string]$label,
        [string]$sql
    )
    Log ""
    Log "--- $label ---"
    try {
        $result = docker exec sparetrack-postgres psql -U sparetrack_user -d sparetrack -t -A -c $sql 2>&1 | Out-String
        if ($result.Trim()) {
            Add-Content -Path $logFile -Value $result.Trim()
            $lines = $result.Trim() -split "`n"
            $preview = ($lines | Select-Object -First 15) -join "`n"
            Write-Host $preview
            if ($lines.Count -gt 15) { Write-Host "  ... ($($lines.Count) total lines)" }
        } else {
            Log "  (no results)"
        }
    } catch {
        Log "  ERROR: $($_.Exception.Message)"
    }
}

RunSQL "Database Size" "SELECT pg_size_pretty(pg_database_size('sparetrack')) AS database_size;"

RunSQL "Table Sizes (Top 25)" @"
SELECT 
    schemaname || '.' || relname AS table_name,
    pg_size_pretty(pg_total_relation_size(relid)) AS total_size,
    pg_size_pretty(pg_relation_size(relid)) AS data_size,
    pg_size_pretty(pg_total_relation_size(relid) - pg_relation_size(relid)) AS index_size,
    n_live_tup AS approx_row_count
FROM pg_stat_user_tables 
ORDER BY pg_total_relation_size(relid) DESC 
LIMIT 25;
"@

RunSQL "Table Row Counts (all tables)" @"
SELECT relname AS table_name, n_live_tup AS approx_rows 
FROM pg_stat_user_tables 
ORDER BY n_live_tup DESC;
"@

RunSQL "Existing Indexes and Usage" @"
SELECT 
    schemaname || '.' || relname AS table_name,
    indexrelname AS index_name,
    idx_scan AS times_used,
    pg_size_pretty(pg_relation_size(indexrelid)) AS index_size
FROM pg_stat_user_indexes 
ORDER BY idx_scan DESC 
LIMIT 30;
"@

RunSQL "Unused Indexes (idx_scan = 0, wasting write overhead)" @"
SELECT 
    schemaname || '.' || relname AS table_name,
    indexrelname AS index_name,
    pg_size_pretty(pg_relation_size(indexrelid)) AS index_size
FROM pg_stat_user_indexes 
WHERE idx_scan = 0 
AND indexrelname NOT LIKE '%pkey%'
AND indexrelname NOT LIKE '%unique%'
ORDER BY pg_relation_size(indexrelid) DESC;
"@

RunSQL "Sequential Scan Hotspots (tables doing full-table scans)" @"
SELECT 
    relname AS table_name,
    seq_scan,
    seq_tup_read,
    idx_scan,
    CASE WHEN (seq_scan + idx_scan) > 0 
        THEN round(100.0 * idx_scan / (seq_scan + idx_scan), 1) 
        ELSE 0 
    END AS idx_usage_pct,
    n_live_tup AS approx_rows
FROM pg_stat_user_tables 
WHERE seq_scan > 0 
ORDER BY seq_tup_read DESC 
LIMIT 20;
"@

RunSQL "Dead Tuples (tables needing VACUUM)" @"
SELECT 
    relname AS table_name,
    n_live_tup AS live_rows,
    n_dead_tup AS dead_rows,
    CASE WHEN n_live_tup > 0 
        THEN round(100.0 * n_dead_tup / n_live_tup, 1) 
        ELSE 0 
    END AS dead_pct,
    last_autovacuum,
    last_autoanalyze
FROM pg_stat_user_tables 
WHERE n_dead_tup > 50 
ORDER BY n_dead_tup DESC;
"@

RunSQL "Cache Hit Ratio (target: >99%)" @"
SELECT 
    'Heap (table data)' AS cache_type,
    CASE WHEN sum(heap_blks_hit) + sum(heap_blks_read) > 0
        THEN round(100.0 * sum(heap_blks_hit) / (sum(heap_blks_hit) + sum(heap_blks_read)), 2)
        ELSE 100
    END AS hit_ratio_pct
FROM pg_statio_user_tables
UNION ALL
SELECT 
    'Index' AS cache_type,
    CASE WHEN sum(idx_blks_hit) + sum(idx_blks_read) > 0
        THEN round(100.0 * sum(idx_blks_hit) / (sum(idx_blks_hit) + sum(idx_blks_read)), 2)
        ELSE 100
    END AS hit_ratio_pct
FROM pg_statio_user_indexes;
"@

RunSQL "Active Database Connections" @"
SELECT state, count(*) AS connection_count 
FROM pg_stat_activity 
WHERE datname = 'sparetrack'
GROUP BY state 
ORDER BY connection_count DESC;
"@

RunSQL "Long-Running Queries (active > 5 seconds)" @"
SELECT 
    pid,
    now() - query_start AS duration,
    state,
    left(query, 200) AS query_preview
FROM pg_stat_activity 
WHERE datname = 'sparetrack'
AND state = 'active' 
AND now() - query_start > interval '5 seconds'
ORDER BY query_start;
"@

RunSQL "Lock Contention (blocked queries)" @"
SELECT 
    blocked_locks.pid AS blocked_pid,
    blocked_activity.query AS blocked_query,
    blocking_locks.pid AS blocking_pid,
    blocking_activity.query AS blocking_query
FROM pg_catalog.pg_locks blocked_locks
JOIN pg_catalog.pg_stat_activity blocked_activity ON blocked_activity.pid = blocked_locks.pid
JOIN pg_catalog.pg_locks blocking_locks ON blocking_locks.locktype = blocked_locks.locktype
    AND blocking_locks.database IS NOT DISTINCT FROM blocked_locks.database
    AND blocking_locks.relation IS NOT DISTINCT FROM blocked_locks.relation
    AND blocking_locks.page IS NOT DISTINCT FROM blocked_locks.page
    AND blocking_locks.tuple IS NOT DISTINCT FROM blocked_locks.tuple
    AND blocking_locks.virtualxid IS NOT DISTINCT FROM blocked_locks.virtualxid
    AND blocking_locks.transactionid IS NOT DISTINCT FROM blocked_locks.transactionid
    AND blocking_locks.classid IS NOT DISTINCT FROM blocked_locks.classid
    AND blocking_locks.objid IS NOT DISTINCT FROM blocked_locks.objid
    AND blocking_locks.objsubid IS NOT DISTINCT FROM blocked_locks.objsubid
    AND blocking_locks.pid != blocked_locks.pid
JOIN pg_catalog.pg_stat_activity blocking_activity ON blocking_activity.pid = blocking_locks.pid
WHERE NOT blocked_locks.granted
LIMIT 10;
"@

RunSQL "PostgreSQL Configuration (performance-critical settings)" @"
SELECT name, setting, unit, 
    CASE 
        WHEN name = 'shared_buffers' THEN 'Memory for DB cache (recommend ~4GB for 56GB RAM)'
        WHEN name = 'effective_cache_size' THEN 'Expected OS cache (recommend ~32GB for 56GB RAM)'
        WHEN name = 'work_mem' THEN 'Per-query sort/hash memory (recommend 32-64MB)'
        WHEN name = 'maintenance_work_mem' THEN 'VACUUM/CREATE INDEX memory'
        WHEN name = 'max_connections' THEN 'Max simultaneous connections'
        WHEN name = 'random_page_cost' THEN 'Should be 1.1 for SSD, 4.0 for HDD'
        WHEN name = 'effective_io_concurrency' THEN 'Should be 200 for SSD, 1-2 for HDD'
        WHEN name = 'max_worker_processes' THEN 'Background worker processes'
        WHEN name = 'max_parallel_workers_per_gather' THEN 'Parallel query workers'
        WHEN name = 'checkpoint_completion_target' THEN 'Checkpoint I/O spread (recommend 0.9)'
        WHEN name = 'wal_buffers' THEN 'WAL write buffer'
        ELSE ''
    END AS recommendation
FROM pg_settings 
WHERE name IN (
    'shared_buffers', 'effective_cache_size', 'work_mem', 'maintenance_work_mem',
    'max_connections', 'random_page_cost', 'effective_io_concurrency',
    'max_worker_processes', 'max_parallel_workers_per_gather',
    'checkpoint_completion_target', 'wal_buffers', 'huge_pages'
)
ORDER BY name;
"@

RunSQL "pg_stat_statements (Top 20 slowest queries - if extension enabled)" @"
DO \$\$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements') THEN
        RAISE NOTICE 'pg_stat_statements IS enabled';
    ELSE
        RAISE NOTICE 'pg_stat_statements is NOT enabled (install it for detailed query stats)';
    END IF;
END \$\$;
SELECT 
    left(query, 150) AS query_preview,
    calls,
    round(total_exec_time::numeric, 2) AS total_ms,
    round(mean_exec_time::numeric, 2) AS avg_ms,
    round(max_exec_time::numeric, 2) AS max_ms,
    rows
FROM pg_stat_statements 
WHERE dbid = (SELECT oid FROM pg_database WHERE datname = 'sparetrack')
ORDER BY total_exec_time DESC 
LIMIT 20;
"@

RunSQL "Database Statistics Summary" @"
SELECT 
    datname,
    numbackends AS active_connections,
    xact_commit AS transactions_committed,
    xact_rollback AS transactions_rolled_back,
    blks_read AS disk_blocks_read,
    blks_hit AS buffer_cache_hits,
    CASE WHEN blks_read + blks_hit > 0 
        THEN round(100.0 * blks_hit / (blks_read + blks_hit), 2) 
        ELSE 100 
    END AS cache_hit_pct,
    tup_returned AS rows_returned,
    tup_fetched AS rows_fetched,
    tup_inserted AS rows_inserted,
    tup_updated AS rows_updated,
    tup_deleted AS rows_deleted
FROM pg_stat_database 
WHERE datname = 'sparetrack';
"@

# ============================================================================
# SECTION 7: API ENDPOINT PERFORMANCE (from localhost - zero LAN overhead)
# ============================================================================
LogSection "SECTION 7: API ENDPOINT TIMING (localhost)"

Log "Timing API endpoints from the server itself (127.0.0.1:8080)"
Log "This isolates pure server/backend processing time from any LAN latency."
Log ""

# First, get an auth token
Log "--- Authenticating to get API token ---"
$authToken = $null
try {
    $loginBody = @{
        email = "admin@sparetrack.internal"
        password = "password123"
    } | ConvertTo-Json

    $loginTime = Measure-Command {
        $loginResponse = Invoke-WebRequest -Uri "http://127.0.0.1:8080/api/v1/auth/login" `
            -Method POST `
            -Body $loginBody `
            -ContentType "application/json" `
            -UseBasicParsing `
            -ErrorAction Stop
    }

    $loginData = $loginResponse.Content | ConvertFrom-Json
    $authToken = $loginData.token
    if (-not $authToken -and $loginData.data -and $loginData.data.token) {
        $authToken = $loginData.data.token
    }
    if (-not $authToken -and $loginData.access_token) {
        $authToken = $loginData.access_token
    }

    Log "  Login: $([math]::Round($loginTime.TotalMilliseconds,0))ms - Token obtained: $(if($authToken){'YES'}else{'NO - will try without auth'})"
    Add-Content -Path $logFile -Value "  Auth token length: $(if($authToken){$authToken.Length}else{0})"
} catch {
    Log "  Login FAILED: $($_.Exception.Message)"
    Log "  Will attempt endpoints without authentication (may get 401s)"
}

# API timing function
function TimeEndpoint {
    param(
        [string]$method,
        [string]$path,
        [string]$label,
        [int]$iterations = 3
    )

    Log ""
    Log "--- $label ---"
    Log "  Endpoint: $method $path"

    $times = @()
    $sizes = @()
    $statuses = @()

    for ($i = 1; $i -le $iterations; $i++) {
        try {
            $headers = @{ "Accept" = "application/json" }
            if ($authToken) {
                $headers["Authorization"] = "Bearer $authToken"
            }

            $elapsed = Measure-Command {
                $response = Invoke-WebRequest -Uri "http://127.0.0.1:8080/api/v1/$path" `
                    -Method $method `
                    -Headers $headers `
                    -UseBasicParsing `
                    -TimeoutSec 120 `
                    -ErrorAction Stop
            }

            $ms = [math]::Round($elapsed.TotalMilliseconds, 0)
            $sizeKB = [math]::Round($response.Content.Length / 1024, 1)
            $status = $response.StatusCode

            $times += $ms
            $sizes += $sizeKB
            $statuses += $status

            Log "  Run $i : ${ms}ms | ${sizeKB}KB | HTTP $status"
        } catch {
            $errMsg = $_.Exception.Message
            if ($errMsg -match "(\d{3})") {
                $statuses += $Matches[1]
            }
            Log "  Run $i : FAILED - $errMsg"
            $times += -1
        }
    }

    # Summary
    $validTimes = $times | Where-Object { $_ -gt 0 }
    if ($validTimes.Count -gt 0) {
        $avg = [math]::Round(($validTimes | Measure-Object -Average).Average, 0)
        $min = [math]::Round(($validTimes | Measure-Object -Minimum).Minimum, 0)
        $max = [math]::Round(($validTimes | Measure-Object -Maximum).Maximum, 0)
        $avgSize = [math]::Round(($sizes | Measure-Object -Average).Average, 1)

        $verdict = if ($max -gt 10000) { "🔴 CRITICAL (>10s)" }
                   elseif ($max -gt 3000) { "🟡 SLOW (>3s)" }
                   elseif ($max -gt 1000) { "🟠 MODERATE (>1s)" }
                   else { "🟢 FAST (<1s)" }

        Log "  ──────────────────────────────────"
        Log "  RESULT: Min=${min}ms  Avg=${avg}ms  Max=${max}ms  Size=${avgSize}KB  $verdict"
    } else {
        Log "  RESULT: ALL REQUESTS FAILED"
    }
}

# Get a project ID for hierarchy tests
$testProjectId = $null
try {
    if ($authToken) {
        $headers = @{ 
            "Accept" = "application/json"
            "Authorization" = "Bearer $authToken" 
        }
        $projResp = Invoke-WebRequest -Uri "http://127.0.0.1:8080/api/v1/dashboard/project-hierarchy" `
            -Headers $headers -UseBasicParsing -TimeoutSec 60 -ErrorAction Stop
        $projData = $projResp.Content | ConvertFrom-Json
        
        $projectsList = $null
        if ($projData.active_projects) { $projectsList = $projData.active_projects }
        elseif ($projData.projects) { $projectsList = $projData.projects }
        
        if ($projectsList -and $projectsList.Count -gt 0) {
            $testProjectId = $projectsList[0].id
            Log "Using project ID $testProjectId ($($projectsList[0].name)) for hierarchy tests"
            Log "Total projects found: $($projectsList.Count)"
        }
    }
} catch {
    Log "Could not get project list for hierarchy tests: $($_.Exception.Message)"
}

# ---- Time each critical endpoint ----

TimeEndpoint "GET" "health" "Health Check (baseline)"

TimeEndpoint "GET" "dashboard/summary" "Dashboard Summary (ALL types - HEAVIEST ENDPOINT)"

TimeEndpoint "GET" "dashboard/summary?part_type=MFG" "Dashboard Summary (MFG only)"

TimeEndpoint "GET" "dashboard/summary?part_type=BOP" "Dashboard Summary (BOP only)"

TimeEndpoint "GET" "dashboard/summary?part_type=STD" "Dashboard Summary (STD only)"

TimeEndpoint "GET" "dashboard/project-hierarchy" "Project Hierarchy (project list, no drill-down)"

if ($testProjectId) {
    TimeEndpoint "GET" "dashboard/project-hierarchy?project_id=$testProjectId" "Project Hierarchy (full drill-down for project $testProjectId)"
    
    TimeEndpoint "GET" "dashboard/project-hierarchy?project_id=$testProjectId&part_type=MFG" "Project Hierarchy (MFG drill-down for project $testProjectId)"
}

TimeEndpoint "GET" "dashboard/kpi-drilldown?kpi=total_parts" "KPI Drilldown (total_parts)"

TimeEndpoint "GET" "store/hierarchy" "Store Department Hierarchy"

TimeEndpoint "GET" "qc/hierarchy" "QC Department Hierarchy"

TimeEndpoint "GET" "rework/hierarchy" "Rework Department Hierarchy"

TimeEndpoint "GET" "paint/hierarchy" "Paint Department Hierarchy"

TimeEndpoint "GET" "assembly/hierarchy" "Assembly Department Hierarchy"

TimeEndpoint "GET" "ecn/dashboard/summary" "ECN Dashboard Summary"

TimeEndpoint "GET" "supplier-allocation/hierarchy" "Supplier Allocation Hierarchy"

TimeEndpoint "GET" "supplier-allocation/overview" "Supplier Allocation Overview Table"

if ($testProjectId) {
    TimeEndpoint "GET" "store/hierarchy?project_id=$testProjectId" "Store Hierarchy (project drill-down)"
    
    TimeEndpoint "GET" "qc/hierarchy?project_id=$testProjectId" "QC Hierarchy (project drill-down)"
}

# ============================================================================
# SECTION 8: PERFORMANCE SUMMARY
# ============================================================================
LogSection "SECTION 8: DIAGNOSTIC SUMMARY"

$endTime = Get-Date
$totalDuration = $endTime - $startTime

Log ""
Log "Diagnostic completed in $([math]::Round($totalDuration.TotalSeconds,1)) seconds"
Log "All results saved to: $logFile"
Log ""
Log "╔══════════════════════════════════════════════════════════════════╗"
Log "║  NEXT STEPS:                                                    ║"
Log "║                                                                  ║"
Log "║  1. Share the file '$logFile' back              ║"
Log "║     (copy to USB, or add to git and push)                       ║"
Log "║                                                                  ║"
Log "║  2. To add to git:                                              ║"
Log "║     git add $logFile                            ║"
Log "║     git commit -m 'Add server diagnostic output'                ║"
Log "║     git push origin branch-a                                    ║"
Log "║                                                                  ║"
Log "║  REMINDER: This script made ZERO changes to your system.        ║"
Log "║  Your application is still running normally.                    ║"
Log "╚══════════════════════════════════════════════════════════════════╝"
Log ""

# Open the log file in notepad for review
Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "  DIAGNOSTIC COMPLETE!" -ForegroundColor Green
Write-Host "  Log file: $logFile" -ForegroundColor Green
Write-Host "  Duration: $([math]::Round($totalDuration.TotalSeconds,1)) seconds" -ForegroundColor Green
Write-Host "  Your app is still running - nothing was changed." -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "Opening log file..." -ForegroundColor Yellow

try { notepad $logFile } catch { Write-Host "Could not open notepad. Log is at: $logFile" }
