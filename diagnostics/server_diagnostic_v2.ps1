#Requires -Version 5.1
<#
SPARETRACK SERVER PERFORMANCE DIAGNOSTIC SCRIPT - VERSION 2 (V2)
================================================================

PURPOSE:
- Deeper forensic performance investigation focused on Project-filter selection,
  department switching (Store, QC, Rework, Paint, Assembly, Purchase, BOP, STD, ECN),
  ECN loading, and mobile data-loading paths.
- Preserves 100% of baseline checks from Version 1 for direct before/after comparability.
- Adds comprehensive project-scoped queries and real-time CPU/RAM sampling during slow requests.

SAFETY GUARANTEE:
- This script is 100% READ-ONLY.
- It does NOT delete any data.
- It does NOT modify any database records.
- It does NOT stop or restart any Docker containers.
- It does NOT stop or restart any Windows services.
- It does NOT modify any files on the server.
- It does NOT install any software.
- It does NOT change any firewall or network settings.
- It does NOT change any registry values.
- It does NOT run any SQL that modifies data (no INSERT/UPDATE/DELETE/TRUNCATE/DROP).
- The application continues running normally during this script.

All output is written to a timestamped log file in the current directory:
sparetrack_diagnostic_v2_<timestamp>.txt
#>

$ErrorActionPreference = "Continue"
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$logFile = "sparetrack_diagnostic_v2_$timestamp.txt"
$startTime = Get-Date

function Log($message) {
    $ts = Get-Date -Format "HH:mm:ss"
    $line = "[$ts] $message"
    Write-Host $line
    Add-Content -Path $logFile -Value $line
}

function LogSection($title) {
    $separator = "================================================================================"
    Log ""
    Log $separator
    Log "  $title"
    Log $separator
}

function SafeRun($label, [scriptblock]$cmd) {
    Log ""
    Log "--- $label ---"
    try {
        $output = & $cmd 2>&1 | Out-String
        $trimmed = $output.Trim()
        if ($trimmed) {
            Add-Content -Path $logFile -Value $trimmed
            $lines = $trimmed -split "`n"
            if ($lines.Count -le 14) {
                Write-Host $trimmed
            } else {
                $preview = ($lines | Select-Object -First 12) -join "`n"
                Write-Host $preview
                Write-Host ("  ... (" + $lines.Count + " total lines, see log file)")
            }
        } else {
            Log "  (no output)"
        }
    } catch {
        $errMsg = $_.Exception.Message
        Log "  ERROR: $errMsg"
    }
}

# ============================================================================
# START
# ============================================================================
Log "SPARETRACK SERVER PERFORMANCE DIAGNOSTIC - VERSION 2"
Log ("Started: " + (Get-Date -Format "yyyy-MM-dd HH:mm:ss"))
Log "Output:  $logFile"
Log "Mode:    READ-ONLY (zero modifications)"
Log ""

# ============================================================================
# SECTION 1: HARDWARE AND SYSTEM INFORMATION
# ============================================================================
LogSection "SECTION 1: HARDWARE AND SYSTEM INFORMATION"

SafeRun "CPU Information" {
    Get-CimInstance Win32_Processor | Format-List Name, NumberOfCores, NumberOfLogicalProcessors, MaxClockSpeed, CurrentClockSpeed, LoadPercentage
}

SafeRun "Operating System and Memory" {
    $os = Get-CimInstance Win32_OperatingSystem
    $totalGB = [math]::Round($os.TotalVisibleMemorySize / 1MB, 2)
    $freeGB = [math]::Round($os.FreePhysicalMemory / 1MB, 2)
    $usedGB = [math]::Round(($os.TotalVisibleMemorySize - $os.FreePhysicalMemory) / 1MB, 2)
    $usedPct = [math]::Round(($os.TotalVisibleMemorySize - $os.FreePhysicalMemory) / $os.TotalVisibleMemorySize * 100, 1)
    Write-Output ("OS:         " + $os.Caption + " " + $os.Version)
    Write-Output ("Build:      " + $os.BuildNumber)
    Write-Output ("Arch:       " + $os.OSArchitecture)
    Write-Output ("Total RAM:  " + $totalGB + " GB")
    Write-Output ("Free RAM:   " + $freeGB + " GB")
    Write-Output ("Used RAM:   " + $usedGB + " GB (" + $usedPct + "%)")
}

SafeRun "Physical Disks (SSD vs HDD detection)" {
    if (Get-Command Get-PhysicalDisk -ErrorAction SilentlyContinue) {
        Get-PhysicalDisk | Format-Table DeviceId, FriendlyName, MediaType, BusType, Size, HealthStatus -AutoSize
    } else {
        Get-Disk | Format-Table Number, FriendlyName, Size, PartitionStyle, OperationalStatus -AutoSize
    }
}

SafeRun "Volume Free Space" {
    Get-Volume | Where-Object { $_.DriveLetter } | ForEach-Object {
        $sizeGB = [math]::Round($_.Size / 1GB, 2)
        $freeGB = [math]::Round($_.SizeRemaining / 1GB, 2)
        $usedPct = 0
        if ($_.Size -gt 0) { $usedPct = [math]::Round(($_.Size - $_.SizeRemaining) / $_.Size * 100, 1) }
        Write-Output ($_.DriveLetter + ":  Size=" + $sizeGB + "GB  Free=" + $freeGB + "GB  Used=" + $usedPct + "%  FS=" + $_.FileSystem)
    }
}

SafeRun "System Uptime" {
    $os = Get-CimInstance Win32_OperatingSystem
    $uptime = (Get-Date) - $os.LastBootUpTime
    Write-Output ("Last Boot: " + $os.LastBootUpTime)
    Write-Output ("Uptime: " + $uptime.Days + " days, " + $uptime.Hours + " hours, " + $uptime.Minutes + " minutes")
}

# ============================================================================
# SECTION 2: LIVE PERFORMANCE COUNTERS
# ============================================================================
LogSection "SECTION 2: LIVE PERFORMANCE COUNTERS"

Log "Capturing 5-second performance snapshot..."

SafeRun "CPU Utilization (5 samples)" {
    $cpuSamples = New-Object System.Collections.ArrayList
    for ($i = 0; $i -lt 5; $i++) {
        try {
            $counter = Get-Counter '\Processor(_Total)\% Processor Time' -ErrorAction Stop
            $val = [math]::Round($counter.CounterSamples[0].CookedValue, 1)
            $null = $cpuSamples.Add($val)
            Write-Output ("  Sample " + ($i+1) + ": " + $val + "%")
        } catch {
            Write-Output ("  Sample " + ($i+1) + ": ERROR")
        }
        if ($i -lt 4) { Start-Sleep -Seconds 1 }
    }
    if ($cpuSamples.Count -gt 0) {
        $avg = [math]::Round(($cpuSamples | Measure-Object -Average).Average, 1)
        $maxVal = [math]::Round(($cpuSamples | Measure-Object -Maximum).Maximum, 1)
        Write-Output ("  Average CPU: " + $avg + "%   Max CPU: " + $maxVal + "%")
    }
}

SafeRun "Memory Counters" {
    try {
        $availMB = (Get-Counter '\Memory\Available MBytes' -ErrorAction Stop).CounterSamples[0].CookedValue
        $availGB = [math]::Round($availMB / 1024, 2)
        $availMBr = [math]::Round($availMB, 0)
        Write-Output ("  Available Memory: " + $availGB + " GB (" + $availMBr + " MB)")
        try {
            $pagesSec = (Get-Counter '\Memory\Pages/sec' -ErrorAction Stop).CounterSamples[0].CookedValue
            $pagesRound = [math]::Round($pagesSec, 0)
            Write-Output ("  Pages/sec (paging activity): " + $pagesRound)
            if ($pagesSec -gt 100) {
                Write-Output "  WARNING: HIGH PAGING ACTIVITY - possible memory pressure"
            } else {
                Write-Output "  OK: Paging activity normal"
            }
        } catch {
            Write-Output "  Pages/sec counter not available"
        }
    } catch {
        Write-Output ("  ERROR reading memory counters: " + $_.Exception.Message)
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
        Write-Output ("  Avg Read Latency:  " + $readMs + " ms")
        Write-Output ("  Avg Write Latency: " + $writeMs + " ms")
        Write-Output ("  Disk Utilization:  " + $diskPct + "%")
        if ($readMs -gt 20) {
            Write-Output "  WARNING: HIGH READ LATENCY (>20ms) - possible HDD or I/O saturation"
        } elseif ($readMs -gt 5) {
            Write-Output "  NOTE: Moderate read latency (5-20ms)"
        } else {
            Write-Output "  OK: Good read latency (<5ms, likely SSD)"
        }
    } catch {
        Write-Output ("  ERROR reading disk counters: " + $_.Exception.Message)
    }
}

# ============================================================================
# SECTION 3: DOCKER CONTAINER DIAGNOSTICS
# ============================================================================
LogSection "SECTION 3: DOCKER CONTAINER DIAGNOSTICS"

SafeRun "Docker Version" {
    docker version 2>&1
}

SafeRun "Docker Info Summary" {
    docker info 2>&1 | Select-String -Pattern "Storage Driver|Docker Root Dir|Total Memory|CPUs|Containers|Server Version|Operating System"
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

SafeRun "PostgreSQL Container Memory Limit" {
    $raw = docker inspect sparetrack-postgres --format "{{.HostConfig.Memory}}" 2>&1
    if ($raw -eq "0") {
        Write-Output "Memory Limit: NONE (container can use all host RAM)"
    } else {
        $mb = [math]::Round([long]$raw / 1MB, 0)
        Write-Output ("Memory Limit: " + $mb + " MB")
    }
    $restart = docker inspect sparetrack-postgres --format "{{.RestartCount}}" 2>&1
    Write-Output ("Restart Count: " + $restart)
}

SafeRun "App Container Memory Limit" {
    $raw = docker inspect sparetrack-app --format "{{.HostConfig.Memory}}" 2>&1
    if ($raw -eq "0") {
        Write-Output "Memory Limit: NONE (container can use all host RAM)"
    } else {
        $mb = [math]::Round([long]$raw / 1MB, 0)
        Write-Output ("Memory Limit: " + $mb + " MB")
    }
    $restart = docker inspect sparetrack-app --format "{{.RestartCount}}" 2>&1
    Write-Output ("Restart Count: " + $restart)
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
    Get-NetAdapter | Where-Object { $_.Status -eq 'Up' } | Format-Table Name, InterfaceDescription, LinkSpeed, MediaType, MacAddress -AutoSize
}

SafeRun "IP Configuration" {
    Get-NetIPConfiguration | Where-Object { $_.IPv4Address } | Format-List InterfaceAlias, IPv4Address, IPv4DefaultGateway, DNSServer
}

# ============================================================================
# SECTION 5: TOP RESOURCE CONSUMERS
# ============================================================================
LogSection "SECTION 5: TOP RESOURCE CONSUMERS"

SafeRun "Top 20 Processes by Memory" {
    Get-Process | Sort-Object WorkingSet64 -Descending | Select-Object -First 20 Name, Id, @{N='Memory_MB';E={[math]::Round($_.WorkingSet64/1MB,1)}}, @{N='CPU_Sec';E={[math]::Round($_.CPU,1)}} | Format-Table -AutoSize
}

# ============================================================================
# SECTION 6: POSTGRESQL DATABASE DIAGNOSTICS (READ-ONLY SQL)
# ============================================================================
LogSection "SECTION 6: POSTGRESQL DATABASE DIAGNOSTICS"

Log "Running read-only SQL diagnostics via docker exec..."
Log "(All queries are SELECT-only. Zero data modifications.)"

function RunSQL($label, $sql) {
    Log ""
    Log "--- $label ---"
    try {
        $result = docker exec sparetrack-postgres psql -U sparetrack_user -d sparetrack -c $sql 2>&1 | Out-String
        $trimmed = $result.Trim()
        if ($trimmed) {
            Add-Content -Path $logFile -Value $trimmed
            $lines = $trimmed -split "`n"
            if ($lines.Count -le 20) {
                Write-Host $trimmed
            } else {
                $preview = ($lines | Select-Object -First 15) -join "`n"
                Write-Host $preview
                Write-Host ("  ... (" + $lines.Count + " total lines)")
            }
        } else {
            Log "  (no results)"
        }
    } catch {
        Log ("  ERROR: " + $_.Exception.Message)
    }
}

RunSQL "Database Size" "SELECT pg_size_pretty(pg_database_size('sparetrack')) AS database_size;"

RunSQL "Table Sizes (Top 25)" "SELECT schemaname || '.' || relname AS table_name, pg_size_pretty(pg_total_relation_size(relid)) AS total_size, pg_size_pretty(pg_relation_size(relid)) AS data_size, n_live_tup AS approx_rows FROM pg_stat_user_tables ORDER BY pg_total_relation_size(relid) DESC LIMIT 25;"

RunSQL "Table Row Counts" "SELECT relname AS table_name, n_live_tup AS approx_rows FROM pg_stat_user_tables ORDER BY n_live_tup DESC;"

RunSQL "Existing Indexes and Usage" "SELECT schemaname || '.' || relname AS table_name, indexrelname AS index_name, idx_scan AS times_used, pg_size_pretty(pg_relation_size(indexrelid)) AS index_size FROM pg_stat_user_indexes ORDER BY idx_scan DESC LIMIT 35;"

RunSQL "Unused Indexes (wasting write overhead)" "SELECT schemaname || '.' || relname AS table_name, indexrelname AS index_name, pg_size_pretty(pg_relation_size(indexrelid)) AS index_size FROM pg_stat_user_indexes WHERE idx_scan = 0 AND indexrelname NOT LIKE '%pkey%' ORDER BY pg_relation_size(indexrelid) DESC;"

RunSQL "Sequential Scan Hotspots (full-table scans)" "SELECT relname AS table_name, seq_scan, seq_tup_read, idx_scan, CASE WHEN (seq_scan + idx_scan) > 0 THEN round(100.0 * idx_scan / (seq_scan + idx_scan), 1) ELSE 0 END AS idx_usage_pct, n_live_tup AS approx_rows FROM pg_stat_user_tables WHERE seq_scan > 0 ORDER BY seq_tup_read DESC LIMIT 25;"

RunSQL "Dead Tuples (tables needing VACUUM)" "SELECT relname AS table_name, n_live_tup AS live_rows, n_dead_tup AS dead_rows, last_autovacuum, last_autoanalyze FROM pg_stat_user_tables WHERE n_dead_tup > 50 ORDER BY n_dead_tup DESC;"

RunSQL "Cache Hit Ratio" "SELECT 'Heap (table data)' AS cache_type, CASE WHEN sum(heap_blks_hit) + sum(heap_blks_read) > 0 THEN round(100.0 * sum(heap_blks_hit) / (sum(heap_blks_hit) + sum(heap_blks_read)), 2) ELSE 100 END AS hit_ratio_pct FROM pg_statio_user_tables UNION ALL SELECT 'Index' AS cache_type, CASE WHEN sum(idx_blks_hit) + sum(idx_blks_read) > 0 THEN round(100.0 * sum(idx_blks_hit) / (sum(idx_blks_hit) + sum(idx_blks_read)), 2) ELSE 100 END AS hit_ratio_pct FROM pg_statio_user_indexes;"

RunSQL "Active Database Connections" "SELECT state, count(*) AS connection_count FROM pg_stat_activity WHERE datname = 'sparetrack' GROUP BY state ORDER BY connection_count DESC;"

RunSQL "Long-Running Queries (active > 5 seconds)" "SELECT pid, now() - query_start AS duration, state, left(query, 200) AS query_preview FROM pg_stat_activity WHERE datname = 'sparetrack' AND state = 'active' AND now() - query_start > interval '5 seconds' ORDER BY query_start;"

RunSQL "PostgreSQL Configuration (critical settings)" "SELECT name, setting, unit, short_desc FROM pg_settings WHERE name IN ('shared_buffers', 'effective_cache_size', 'work_mem', 'maintenance_work_mem', 'max_connections', 'random_page_cost', 'effective_io_concurrency', 'max_worker_processes', 'max_parallel_workers_per_gather', 'checkpoint_completion_target', 'wal_buffers', 'huge_pages') ORDER BY name;"

RunSQL "Database Statistics Summary" "SELECT datname, numbackends AS active_connections, xact_commit AS transactions_committed, xact_rollback AS transactions_rolled_back, blks_read AS disk_blocks_read, blks_hit AS buffer_cache_hits, CASE WHEN blks_read + blks_hit > 0 THEN round(100.0 * blks_hit / (blks_read + blks_hit), 2) ELSE 100 END AS cache_hit_pct, tup_returned AS rows_returned, tup_fetched AS rows_fetched, tup_inserted AS rows_inserted, tup_updated AS rows_updated, tup_deleted AS rows_deleted FROM pg_stat_database WHERE datname = 'sparetrack';"

RunSQL "Check pg_stat_statements extension" "SELECT extname, extversion FROM pg_extension WHERE extname = 'pg_stat_statements';"

# ============================================================================
# SECTION 7: API ENDPOINT TIMING & PROJECT FILTER BENCHMARKS (V2 EXPANDED)
# ============================================================================
LogSection "SECTION 7: API ENDPOINT TIMING & PROJECT FILTER BENCHMARKS"

Log "Timing API endpoints from the server loopback (127.0.0.1:8080)"
Log "This isolates pure application & database processing from LAN/Wi-Fi latency."
Log ""

# Authenticate
Log "--- Authenticating to obtain API token ---"
$authToken = $null
try {
    $loginBody = '{"email":"admin@sparetrack.internal","password":"password123"}'
    $loginStart = Get-Date
    $loginResponse = Invoke-WebRequest -Uri "http://127.0.0.1:8080/api/v1/auth/login" -Method POST -Body $loginBody -ContentType "application/json" -UseBasicParsing -ErrorAction Stop
    $loginEnd = Get-Date
    $loginMs = [math]::Round(($loginEnd - $loginStart).TotalMilliseconds, 0)
    $loginData = $loginResponse.Content | ConvertFrom-Json
    
    if ($loginData.token) { $authToken = $loginData.token }
    elseif ($loginData.data -and $loginData.data.token) { $authToken = $loginData.data.token }
    elseif ($loginData.access_token) { $authToken = $loginData.access_token }
    
    if ($authToken) {
        Log ("  Login OK: " + $loginMs + "ms - Token obtained")
    } else {
        Log ("  Login returned " + $loginMs + "ms but no token found in response")
        Log ("  Response keys: " + (($loginData | Get-Member -MemberType NoteProperty).Name -join ", "))
    }
} catch {
    Log ("  Login FAILED: " + $_.Exception.Message)
    Log "  Will attempt endpoints without authentication"
}

# API timing function
function TimeEndpoint($method, $path, $label, $iterations) {
    if (-not $iterations) { $iterations = 2 }
    
    Log ""
    Log "--- $label ---"
    Log ("  Endpoint: " + $method + " /api/v1/" + $path)
    
    $validTimes = New-Object System.Collections.ArrayList
    $validSizes = New-Object System.Collections.ArrayList
    
    for ($i = 1; $i -le $iterations; $i++) {
        try {
            $headers = @{ "Accept" = "application/json" }
            if ($authToken) { $headers["Authorization"] = "Bearer $authToken" }
            
            $reqStart = Get-Date
            $response = Invoke-WebRequest -Uri ("http://127.0.0.1:8080/api/v1/" + $path) -Method $method -Headers $headers -UseBasicParsing -TimeoutSec 120 -ErrorAction Stop
            $reqEnd = Get-Date
            
            $ms = [math]::Round(($reqEnd - $reqStart).TotalMilliseconds, 0)
            $sizeKB = [math]::Round($response.Content.Length / 1024, 1)
            $status = $response.StatusCode
            
            $null = $validTimes.Add($ms)
            $null = $validSizes.Add($sizeKB)
            
            Log ("  Run " + $i + ": " + $ms + "ms | " + $sizeKB + "KB | HTTP " + $status)
        } catch {
            Log ("  Run " + $i + ": FAILED - " + $_.Exception.Message)
        }
    }
    
    if ($validTimes.Count -gt 0) {
        $avg = [math]::Round(($validTimes | Measure-Object -Average).Average, 0)
        $minVal = [math]::Round(($validTimes | Measure-Object -Minimum).Minimum, 0)
        $maxVal = [math]::Round(($validTimes | Measure-Object -Maximum).Maximum, 0)
        $avgSize = [math]::Round(($validSizes | Measure-Object -Average).Average, 1)
        
        $verdict = "FAST (<1s)"
        if ($maxVal -gt 10000) { $verdict = "CRITICAL (>10s)" }
        elseif ($maxVal -gt 3000) { $verdict = "SLOW (>3s)" }
        elseif ($maxVal -gt 1000) { $verdict = "MODERATE (>1s)" }
        
        Log "  ----------------------------------------"
        Log ("  RESULT: Min=" + $minVal + "ms  Avg=" + $avg + "ms  Max=" + $maxVal + "ms  Size=" + $avgSize + "KB  " + $verdict)
    } else {
        Log "  RESULT: ALL REQUESTS FAILED"
    }
}

# Discover projects for project-specific profiling
$testProjectId = $null
$testProjectName = $null
try {
    if ($authToken) {
        $headers = @{ "Accept" = "application/json"; "Authorization" = "Bearer $authToken" }
        $projResp = Invoke-WebRequest -Uri "http://127.0.0.1:8080/api/v1/dashboard/project-hierarchy" -Headers $headers -UseBasicParsing -TimeoutSec 60 -ErrorAction Stop
        $projData = $projResp.Content | ConvertFrom-Json
        
        $projectsList = $null
        if ($projData.active_projects) { $projectsList = $projData.active_projects }
        elseif ($projData.projects) { $projectsList = $projData.projects }
        
        if ($projectsList -and $projectsList.Count -gt 0) {
            $testProjectId = $projectsList[0].id
            $testProjectName = $projectsList[0].name
            Log ("Discovered active test project: ID=" + $testProjectId + " (" + $testProjectName + ")")
            Log ("Total projects found: " + $projectsList.Count)
        }
    }
} catch {
    Log ("Could not fetch project list for testing: " + $_.Exception.Message)
}

# 1. Baseline Framework & System Endpoints
TimeEndpoint "GET" "health" "Baseline Framework Boot (Health Check)" 2

# 2. Unscoped Dashboard Endpoints (Baseline from V1)
TimeEndpoint "GET" "dashboard/summary" "Dashboard Summary - ALL Types (Global unscoped)" 2
TimeEndpoint "GET" "dashboard/summary?part_type=MFG" "Dashboard Summary - MFG Only (Global unscoped)" 2
TimeEndpoint "GET" "dashboard/summary?part_type=BOP" "Dashboard Summary - BOP Only (Global unscoped)" 2
TimeEndpoint "GET" "dashboard/summary?part_type=STD" "Dashboard Summary - STD Only (Global unscoped)" 2
TimeEndpoint "GET" "dashboard/project-hierarchy" "Project Hierarchy (Project list only)" 2

# 3. Targeted Project-Scoped Filter Benchmarks (NEW IN V2)
if ($testProjectId) {
    Log ""
    Log "=== PROJECT FILTER SELECTION PROFILING (Project ID: $testProjectId) ==="
    TimeEndpoint "GET" ("dashboard/summary?project_id=" + $testProjectId) "Dashboard Summary WITH Project Filter (ALL Types)" 2
    TimeEndpoint "GET" ("dashboard/summary?project_id=" + $testProjectId + "&part_type=MFG") "Dashboard Summary WITH Project Filter (MFG)" 2
    TimeEndpoint "GET" ("dashboard/summary?project_id=" + $testProjectId + "&part_type=BOP") "Dashboard Summary WITH Project Filter (BOP)" 2
    TimeEndpoint "GET" ("dashboard/summary?project_id=" + $testProjectId + "&part_type=STD") "Dashboard Summary WITH Project Filter (STD)" 2

    TimeEndpoint "GET" ("dashboard/project-hierarchy?project_id=" + $testProjectId) "Project Hierarchy Drilldown (ALL Types - Heaviest Payload)" 2
    TimeEndpoint "GET" ("dashboard/project-hierarchy?project_id=" + $testProjectId + "&part_type=MFG") "Project Hierarchy Drilldown (MFG Only)" 2
    TimeEndpoint "GET" ("dashboard/project-hierarchy?project_id=" + $testProjectId + "&part_type=BOP") "Project Hierarchy Drilldown (BOP Only)" 2
    TimeEndpoint "GET" ("dashboard/project-hierarchy?project_id=" + $testProjectId + "&part_type=STD") "Project Hierarchy Drilldown (STD Only)" 2
}

# 4. Department Hierarchy Switching Benchmarks (NEW IN V2)
Log ""
Log "=== DEPARTMENT SWITCHING PROFILING ==="
TimeEndpoint "GET" "store/hierarchy" "Store Department Hierarchy (Unscoped)" 2
TimeEndpoint "GET" "qc/hierarchy" "QC Department Hierarchy (Unscoped)" 2
TimeEndpoint "GET" "rework/hierarchy" "Rework Department Hierarchy (Unscoped)" 2
TimeEndpoint "GET" "paint/hierarchy" "Paint Department Hierarchy (Unscoped)" 2
TimeEndpoint "GET" "assembly/hierarchy" "Assembly Department Hierarchy (Unscoped)" 2
TimeEndpoint "GET" "supplier-allocation/hierarchy" "Supplier Allocation Hierarchy" 2
TimeEndpoint "GET" "supplier-allocation/overview" "Supplier Allocation Overview" 2

if ($testProjectId) {
    TimeEndpoint "GET" ("store/hierarchy?project_id=" + $testProjectId) "Store Department Drilldown WITH Project Filter" 2
    TimeEndpoint "GET" ("qc/hierarchy?project_id=" + $testProjectId) "QC Department Drilldown WITH Project Filter" 2
    TimeEndpoint "GET" ("rework/hierarchy?project_id=" + $testProjectId) "Rework Department Drilldown WITH Project Filter" 2
    TimeEndpoint "GET" ("paint/hierarchy?project_id=" + $testProjectId) "Paint Department Drilldown WITH Project Filter" 2
    TimeEndpoint "GET" ("assembly/hierarchy?project_id=" + $testProjectId) "Assembly Department Drilldown WITH Project Filter" 2
}

# 5. ECN Benchmarks (NEW IN V2)
Log ""
Log "=== ECN PROFILING ==="
TimeEndpoint "GET" "ecn/summary" "ECN Summary Dashboard" 2
TimeEndpoint "GET" "ecn/hierarchy" "ECN Department Hierarchy" 2
TimeEndpoint "GET" "ecn/drilldown" "ECN Drilldown (Global)" 2
if ($testProjectId) {
    TimeEndpoint "GET" ("ecn/summary?project_id=" + $testProjectId) "ECN Summary WITH Project Filter" 2
    TimeEndpoint "GET" ("ecn/hierarchy?project_id=" + $testProjectId) "ECN Hierarchy WITH Project Filter" 2
}

# 6. Mobile Endpoint Benchmarks (NEW IN V2)
Log ""
Log "=== MOBILE DATA-LOADING PATH PROFILING ==="
if ($testProjectId) {
    TimeEndpoint "GET" ("mobile/store/hierarchy?project_id=" + $testProjectId) "Mobile Store Hierarchy WITH Project Filter" 2
}
TimeEndpoint "GET" "bop/parts" "BOP Parts Intake Queue" 2
TimeEndpoint "GET" "std/parts" "STD Parts Intake Queue" 2

# 7. Real-Time Resource Sampling During a Heavy Drilldown Request (NEW IN V2)
if ($testProjectId) {
    Log ""
    Log "=== REAL-TIME HARDWARE UTILIZATION DURING PROJECT HIERARCHY REQUEST ==="
    try {
        $headers = @{ "Accept" = "application/json" }
        if ($authToken) { $headers["Authorization"] = "Bearer $authToken" }
        
        $cpuBefore = (Get-Counter '\Processor(_Total)\% Processor Time' -ErrorAction SilentlyContinue).CounterSamples[0].CookedValue
        $memBefore = (Get-Counter '\Memory\Available MBytes' -ErrorAction SilentlyContinue).CounterSamples[0].CookedValue
        
        $drillStart = Get-Date
        $response = Invoke-WebRequest -Uri ("http://127.0.0.1:8080/api/v1/dashboard/project-hierarchy?project_id=" + $testProjectId) -Headers $headers -UseBasicParsing -TimeoutSec 120 -ErrorAction Stop
        $drillEnd = Get-Date
        
        $cpuAfter = (Get-Counter '\Processor(_Total)\% Processor Time' -ErrorAction SilentlyContinue).CounterSamples[0].CookedValue
        $memAfter = (Get-Counter '\Memory\Available MBytes' -ErrorAction SilentlyContinue).CounterSamples[0].CookedValue
        $drillMs = [math]::Round(($drillEnd - $drillStart).TotalMilliseconds, 0)
        $drillSizeKB = [math]::Round($response.Content.Length / 1024, 1)
        
        Log ("  Drilldown Duration: " + $drillMs + " ms | Payload: " + $drillSizeKB + " KB")
        Log ("  CPU Before Request: " + [math]::Round($cpuBefore, 1) + "% | CPU After Request: " + [math]::Round($cpuAfter, 1) + "%")
        Log ("  Available RAM Before: " + [math]::Round($memBefore, 0) + " MB | Available RAM After: " + [math]::Round($memAfter, 0) + " MB")
    } catch {
        Log ("  Hardware monitoring sample failed: " + $_.Exception.Message)
    }
}

# ============================================================================
# SECTION 8: SUMMARY & COMPLETION
# ============================================================================
LogSection "SECTION 8: DIAGNOSTIC V2 COMPLETE"

$endTime = Get-Date
$totalDuration = $endTime - $startTime
$totalSec = [math]::Round($totalDuration.TotalSeconds, 1)

Log ""
Log ("Diagnostic V2 completed in " + $totalSec + " seconds")
Log ("All results saved to: " + $logFile)
Log ""
Log "NEXT STEPS FOR OPERATOR:"
Log "  1. Copy or push the log file ($logFile) for analysis."
Log "  2. If using git to push output:"
Log ("     git add " + $logFile)
Log "     git commit -m 'Add server diagnostic V2 output'"
Log "     git push origin branch-a"
Log ""
Log "REMINDER: This script made ZERO changes to your system."
Log "Your database, containers, and data remain 100% untouched."

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "  DIAGNOSTIC V2 COMPLETE" -ForegroundColor Green
Write-Host ("  Log file: " + $logFile) -ForegroundColor Green
Write-Host ("  Duration: " + $totalSec + " seconds") -ForegroundColor Green
Write-Host "  App is still running - zero data modified." -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green

try { notepad $logFile } catch { Write-Host ("Log saved at: " + $logFile) }
