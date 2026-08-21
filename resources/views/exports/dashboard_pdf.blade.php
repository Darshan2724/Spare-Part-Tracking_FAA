<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'FAITH AUTOMATION — Dashboard Report' }}</title>
    <style>
        @page {
            margin: 10mm 10mm 10mm 10mm;
            size: a4 landscape;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 8.5px;
            color: #1e293b;
            line-height: 1.25;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 4px;
        }
        .header-table td {
            vertical-align: middle;
        }
        .brand-title {
            font-size: 14px;
            font-weight: bold;
            color: #0f172a;
            letter-spacing: 0.5px;
        }
        .report-subtitle {
            font-size: 10px;
            color: #2563eb;
            font-weight: 600;
            margin-top: 1px;
        }
        .meta-box {
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 4px 8px;
            margin-bottom: 8px;
            font-size: 8px;
        }
        .meta-item {
            display: inline-block;
            margin-right: 18px;
        }
        .meta-label {
            font-weight: bold;
            color: #475569;
        }
        .meta-val {
            color: #0f172a;
        }
        .section-heading {
            font-size: 9.5px;
            font-weight: bold;
            color: #0f172a;
            margin-top: 6px;
            margin-bottom: 3px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* ------------------------------------------------------------- */
        /* HORIZONTAL ROW 1: PRIMARY EXECUTIVE KPI CARDS                */
        /* ------------------------------------------------------------- */
        .exec-kpi-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 6px 0;
            margin-bottom: 6px;
            margin-left: -6px;
            margin-right: -6px;
        }
        .exec-kpi-card {
            border-radius: 5px;
            padding: 7px 10px;
            color: #ffffff;
            vertical-align: middle;
        }
        .exec-card-blue   { background-color: #2563eb; }
        .exec-card-teal   { background-color: #0d9488; }
        .exec-card-danger { background-color: #ef4444; }
        .exec-card-dark   { background-color: #0f172a; }

        .exec-title {
            font-size: 7.5px;
            text-transform: uppercase;
            font-weight: bold;
            color: rgba(255, 255, 255, 0.85);
            letter-spacing: 0.5px;
        }
        .exec-value {
            font-size: 18px;
            font-weight: bold;
            line-height: 1.1;
            color: #ffffff;
            margin-top: 2px;
        }
        .exec-sub {
            font-size: 7px;
            color: rgba(255, 255, 255, 0.75);
            margin-top: 2px;
        }

        /* ------------------------------------------------------------- */
        /* HORIZONTAL ROW 2: WORKSTATION OPERATIONAL 8-STAGE KPI GRID   */
        /* ------------------------------------------------------------- */
        .op-kpi-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 4px 0;
            margin-bottom: 8px;
            margin-left: -4px;
            margin-right: -4px;
        }
        .op-kpi-card {
            border-radius: 4px;
            padding: 5px 6px;
            color: #ffffff;
            text-align: center;
            vertical-align: top;
        }
        .op-title {
            font-size: 6.8px;
            text-transform: uppercase;
            font-weight: bold;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 2px;
            white-space: nowrap;
        }
        .op-value {
            font-size: 13px;
            font-weight: bold;
            color: #ffffff;
            line-height: 1.1;
        }
        .op-sub {
            font-size: 6.5px;
            color: rgba(255, 255, 255, 0.8);
            margin-top: 2px;
            white-space: nowrap;
        }

        /* Stage Card Colors matching Dashboard UI */
        .card-parts     { background-color: #4f46e5; }
        .card-received  { background-color: #10b981; }
        .card-pending   { background-color: #0f172a; }
        .card-store     { background-color: #d97706; }
        .card-qc        { background-color: #0284c7; }
        .card-rework    { background-color: #ea580c; }
        .card-paint     { background-color: #7c3aed; }
        .card-assembly  { background-color: #db2777; }

        /* ------------------------------------------------------------- */
        /* HORIZONTAL ROW 3: SIDE-BY-SIDE ANALYTICS (TOP PROJ & HEALTH) */
        /* ------------------------------------------------------------- */
        .side-by-side-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 3px;
        }
        .data-table th, .data-table td {
            border: 1px solid #cbd5e1;
            padding: 3.5px 5px;
            text-align: left;
            font-size: 8px;
        }
        .data-table th {
            background-color: #0f172a;
            color: #ffffff;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 7.5px;
            letter-spacing: 0.3px;
        }
        .data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .badge {
            display: inline-block;
            padding: 1px 4px;
            font-size: 7px;
            font-weight: bold;
            border-radius: 3px;
            text-align: center;
        }
        .badge-primary   { background-color: #2563eb; color: #fff; }
        .badge-success   { background-color: #10b981; color: #fff; }
        .badge-danger    { background-color: #ef4444; color: #fff; }
        .badge-warning   { background-color: #f59e0b; color: #fff; }
        .badge-secondary { background-color: #64748b; color: #fff; }
        .badge-dark      { background-color: #0f172a; color: #fff; }

        .footer-table {
            position: fixed;
            bottom: -7mm;
            left: 0;
            right: 0;
            width: 100%;
            border-top: 1px solid #e2e8f0;
            padding-top: 3px;
            font-size: 7.5px;
            color: #64748b;
        }
        .text-center { text-align: center; }
        .text-right  { text-align: right; }
        .fw-bold     { font-weight: bold; }
        .page-break  { page-break-after: always; }
    </style>
</head>
<body>
    <!-- Header Banner -->
    <table class="header-table">
        <tr>
            <td>
                <div class="brand-title">FAITH AUTOMATION — Industrial Spare Parts Tracking System</div>
                <div class="report-subtitle">Manufacturing Manager Terminal &bull; Dashboard Executive Report</div>
            </td>
            <td class="text-right">
                <div style="font-size: 7.5px; color: #64748b;">
                    <strong>Generated:</strong> {{ $generated_at }}<br>
                    <strong>Generated By:</strong> {{ $generated_by }}
                </div>
            </td>
        </tr>
    </table>

    <!-- Active Filters & Scope Box -->
    <div class="meta-box">
        <div class="meta-item">
            <span class="meta-label">Selected Scope:</span>
            <span class="meta-val fw-bold" style="color: #2563eb;">{{ $scope_label }}</span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Total Active Projects:</span>
            <span class="meta-val fw-bold">{{ $active_projects_count ?? ($summary['active_projects'] ?? 0) }}</span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Overall Completion:</span>
            <span class="meta-val fw-bold" style="color: #10b981;">{{ $summary['completion_pct'] ?? 0 }}%</span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Total BOM Requirements:</span>
            <span class="meta-val fw-bold">{{ $summary['total_bom_parts'] ?? 0 }} Pcs</span>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- ROW 1: PRIMARY EXECUTIVE KPI CARDS (Horizontal Row)                       -->
    <!-- ========================================================================= -->
    <div class="section-heading">Row 1 &bull; Executive Project Status</div>
    <table class="exec-kpi-table">
        <tr>
            @if(empty($project_id))
                <!-- 1. Active Projects -->
                <td class="exec-kpi-card exec-card-blue" style="width: 33.3%;">
                    <div class="exec-title">Active Projects</div>
                    <div class="exec-value">{{ $summary['active_projects'] ?? 0 }}</div>
                    <div class="exec-sub">Portfolio In-Progress</div>
                </td>
                <!-- 2. Completed Projects -->
                <td class="exec-kpi-card exec-card-teal" style="width: 33.3%;">
                    <div class="exec-title">Completed Projects</div>
                    <div class="exec-value">{{ $summary['completed_projects'] ?? 0 }}</div>
                    <div class="exec-sub">100% Assembled</div>
                </td>
                <!-- 3. Delayed Projects -->
                <td class="exec-kpi-card exec-card-danger" style="width: 33.3%;">
                    <div class="exec-title">Delayed Projects</div>
                    <div class="exec-value">{{ $summary['delayed_projects'] ?? 0 }}</div>
                    <div class="exec-sub">&gt;14d Inactive &amp; &lt;80%</div>
                </td>
            @else
                <!-- Single Project Selected View -->
                <td class="exec-kpi-card exec-card-dark" style="width: 30%;">
                    <div class="exec-title">{{ $project_code ?? 'PROJECT' }}</div>
                    <div class="exec-value" style="font-size: 13px;">{{ $project_name ?? 'Selected Project' }}</div>
                    <div class="exec-sub">Target Active Project</div>
                </td>
                <td class="exec-kpi-card exec-card-blue" style="width: 23.3%;">
                    <div class="exec-title">Total Jigs / Units</div>
                    <div class="exec-value">{{ $summary['total_jigs'] ?? 0 }} / {{ $summary['total_units'] ?? 0 }}</div>
                    <div class="exec-sub">Production Hierarchy Units</div>
                </td>
                <td class="exec-kpi-card exec-card-teal" style="width: 23.3%;">
                    <div class="exec-title">Assembly Progress</div>
                    <div class="exec-value">{{ $summary['completion_pct'] ?? 0 }}%</div>
                    <div class="exec-sub">{{ $summary['total_received'] ?? 0 }} / {{ $summary['total_bom_parts'] ?? 0 }} Pcs</div>
                </td>
                <td class="exec-kpi-card exec-card-danger" style="width: 23.3%;">
                    <div class="exec-title">Pending Parts</div>
                    <div class="exec-value">{{ $summary['parts_pending'] ?? 0 }}</div>
                    <div class="exec-sub">Missing To Complete</div>
                </td>
            @endif
        </tr>
    </table>

    <!-- ========================================================================= -->
    <!-- ROW 2: WORKSTATION OPERATIONAL 8-STAGE KPI CARDS (Horizontal Row)         -->
    <!-- ========================================================================= -->
    <div class="section-heading" style="margin-top: 8px;">Row 2 &bull; Operational Pipeline &amp; Department Stages</div>
    <table class="op-kpi-table">
        <tr>
            <!-- 1. Total Parts -->
            <td class="op-kpi-card card-parts" style="width: 12.5%;">
                <div class="op-title">Total Parts</div>
                <div class="op-value">{{ $summary['total_bom_parts'] ?? 0 }}</div>
                <div class="op-sub">Required BOM</div>
            </td>
            <!-- 2. Received -->
            <td class="op-kpi-card card-received" style="width: 12.5%;">
                <div class="op-title">Parts Received</div>
                <div class="op-value">{{ $summary['total_received'] ?? 0 }}</div>
                <div class="op-sub">In-Plant Total</div>
            </td>
            <!-- 3. Pending -->
            <td class="op-kpi-card card-pending" style="width: 12.5%;">
                <div class="op-title">Parts Pending</div>
                <div class="op-value">{{ $summary['parts_pending'] ?? 0 }}</div>
                <div class="op-sub">Intake Deficit</div>
            </td>
            <!-- 4. Store -->
            <td class="op-kpi-card card-store" style="width: 12.5%;">
                <div class="op-title">Store</div>
                <div class="op-value">{{ $summary['parts_in_store'] ?? 0 }}</div>
                <div class="op-sub">In Warehouse</div>
            </td>
            <!-- 5. QC -->
            <td class="op-kpi-card card-qc" style="width: 12.5%;">
                <div class="op-title">QC Queue</div>
                <div class="op-value">{{ $summary['qc_inspections'] ?? 0 }}</div>
                <div class="op-sub">Rej: {{ $summary['qc_rejected'] ?? 0 }}</div>
            </td>
            <!-- 6. Rework -->
            <td class="op-kpi-card card-rework" style="width: 12.5%;">
                <div class="op-title">Rework</div>
                <div class="op-value">{{ $summary['rework_queue'] ?? 0 }}</div>
                <div class="op-sub">In Corrections</div>
            </td>
            <!-- 7. Paint -->
            <td class="op-kpi-card card-paint" style="width: 12.5%;">
                <div class="op-title">Paint Shop</div>
                <div class="op-value">{{ $summary['parts_in_paint'] ?? 0 }}</div>
                <div class="op-sub">Done: {{ $summary['paint_completed'] ?? 0 }}</div>
            </td>
            <!-- 8. Assembly -->
            <td class="op-kpi-card card-assembly" style="width: 12.5%;">
                <div class="op-title">Assembly</div>
                <div class="op-value">{{ $summary['parts_in_assembly'] ?? 0 }}</div>
                <div class="op-sub">Done: {{ $summary['assembly_completed'] ?? 0 }}</div>
            </td>
        </tr>
    </table>

    <!-- ========================================================================= -->
    <!-- ROW 3: SIDE-BY-SIDE ANALYTICS (Top Projects Near Completion & Health)     -->
    <!-- ========================================================================= -->
    <div class="section-heading" style="margin-top: 8px;">Row 3 &bull; Portfolio Analytics &amp; Velocity Distribution</div>
    <table class="side-by-side-table">
        <tr>
            <!-- Left Column: Top Projects Near Completion -->
            <td style="width: 58%; vertical-align: top; padding-right: 6px;">
                <div style="font-weight: bold; font-size: 8.5px; color: #0f172a; margin-bottom: 2px;">
                    Top Projects Near Completion <span style="font-size: 7.5px; color: #64748b; font-weight: normal;">(Ranked by % Completion)</span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 32%;">Project Name &amp; Code</th>
                            <th style="width: 15%; text-align: center;">Required</th>
                            <th style="width: 15%; text-align: center;">Received</th>
                            <th style="width: 15%; text-align: center;">Pending</th>
                            <th style="width: 23%; text-align: center;">Completion %</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($top_projects_list as $proj)
                            <tr>
                                <td>
                                    <strong>{{ $proj['name'] }}</strong>
                                    <span style="color: #64748b; font-size: 7px;">({{ $proj['code'] }})</span>
                                </td>
                                <td class="text-center">{{ $proj['required'] }}</td>
                                <td class="text-center fw-bold" style="color: #10b981;">{{ $proj['received'] }}</td>
                                <td class="text-center fw-bold" style="color: #ef4444;">{{ $proj['pending'] }}</td>
                                <td class="text-center">
                                    <span class="badge {{ $proj['percentage'] >= 85 ? 'badge-success' : ($proj['percentage'] >= 50 ? 'badge-primary' : 'badge-warning') }}">
                                        {{ $proj['percentage'] }}%
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center" style="color: #64748b; padding: 10px;">
                                    All active projects have reached 100% completion or no project data available.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </td>

            <!-- Right Column: Project Health Distribution -->
            <td style="width: 42%; vertical-align: top; padding-left: 6px;">
                <div style="font-weight: bold; font-size: 8.5px; color: #0f172a; margin-bottom: 2px;">
                    Project Health Distribution <span style="font-size: 7.5px; color: #64748b; font-weight: normal;">(Portfolio Risk Status)</span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Health Classification</th>
                            <th style="text-align: center; width: 22%;">Count</th>
                            <th style="text-align: center; width: 25%;">Share %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="badge badge-success">Near Completion</span> <span style="font-size: 7px; color: #64748b;">(≥85%)</span></td>
                            <td class="text-center fw-bold">{{ $health_counts['near_completion'] ?? 0 }}</td>
                            <td class="text-center">{{ $health_pcts['near_completion'] ?? 0 }}%</td>
                        </tr>
                        <tr>
                            <td><span class="badge badge-primary">On Track</span> <span style="font-size: 7px; color: #64748b;">(Active last 7d)</span></td>
                            <td class="text-center fw-bold">{{ $health_counts['on_track'] ?? 0 }}</td>
                            <td class="text-center">{{ $health_pcts['on_track'] ?? 0 }}%</td>
                        </tr>
                        <tr>
                            <td><span class="badge badge-warning">At Risk</span> <span style="font-size: 7px; color: #64748b;">(7–14d inactive)</span></td>
                            <td class="text-center fw-bold">{{ $health_counts['at_risk'] ?? 0 }}</td>
                            <td class="text-center">{{ $health_pcts['at_risk'] ?? 0 }}%</td>
                        </tr>
                        <tr>
                            <td><span class="badge badge-danger">Delayed</span> <span style="font-size: 7px; color: #64748b;">(&gt;14d &amp; &lt;80%)</span></td>
                            <td class="text-center fw-bold">{{ $health_counts['delayed'] ?? 0 }}</td>
                            <td class="text-center">{{ $health_pcts['delayed'] ?? 0 }}%</td>
                        </tr>
                        <tr style="background-color: #f1f5f9; font-weight: bold;">
                            <td>Total Active Evaluated</td>
                            <td class="text-center">{{ $health_total ?? 0 }}</td>
                            <td class="text-center">100%</td>
                        </tr>
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <!-- ========================================================================= -->
    <!-- ROW 4+: PROJECT HIERARCHY OR JIGS BREAKDOWN (Page 2)                      -->
    <!-- ========================================================================= -->
    @if(!empty($jigs) && count($jigs) > 0)
        <div class="page-break"></div>

        <!-- Second Page Header -->
        <table class="header-table">
            <tr>
                <td>
                    <div class="brand-title">FAITH AUTOMATION — Project Hierarchy &amp; Jig Breakdown</div>
                    <div class="report-subtitle">{{ $scope_label }} &bull; Level 3 Jigs &amp; Assembly Detail</div>
                </td>
                <td class="text-right">
                    <div style="font-size: 7.5px; color: #64748b;">
                        <strong>Generated:</strong> {{ $generated_at }}
                    </div>
                </td>
            </tr>
        </table>

        <div class="section-heading">Row 4 &bull; Jig Production Units &amp; Assembly Status</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 25%;">Jig Name</th>
                    <th style="width: 15%; text-align: center;">Total Units</th>
                    <th style="width: 15%; text-align: center;">Required (Pcs)</th>
                    <th style="width: 15%; text-align: center;">Received (Pcs)</th>
                    <th style="width: 15%; text-align: center;">Pending (Pcs)</th>
                    <th style="width: 15%; text-align: center;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($jigs as $jig)
                    <tr>
                        <td class="fw-bold" style="color: #2563eb;">{{ $jig['jig_name'] }}</td>
                        <td class="text-center">{{ count($jig['units'] ?? []) }} Units</td>
                        <td class="text-center">{{ $jig['total_required'] ?? 0 }}</td>
                        <td class="text-center text-success fw-bold">{{ $jig['total_received'] ?? 0 }}</td>
                        <td class="text-center text-danger fw-bold">{{ $jig['pending_quantity'] ?? 0 }}</td>
                        <td class="text-center">
                            <span class="badge {{ !empty($jig['is_complete']) ? 'badge-success' : 'badge-warning' }}">
                                {{ !empty($jig['is_complete']) ? 'COMPLETED' : 'IN PROGRESS' }} ({{ $jig['completion_pct'] ?? 0 }}%)
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Parts Inventory Table Sample for Project -->
        @if(!empty($parts_sample) && count($parts_sample) > 0)
            <div class="section-heading" style="margin-top: 10px;">Part Inventory Sample Detail</div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 25%;">Standard Part No</th>
                        <th style="width: 15%;">Item No</th>
                        <th style="width: 20%;">Supplier</th>
                        <th style="width: 8%; text-align: center;">Side</th>
                        <th style="width: 8%; text-align: center;">Req</th>
                        <th style="width: 8%; text-align: center;">Rec</th>
                        <th style="width: 8%; text-align: center;">Pend</th>
                        <th style="width: 8%; text-align: center;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($parts_sample as $part)
                        <tr>
                            <td class="fw-bold">{{ $part['standard_part_no'] }}</td>
                            <td>{{ $part['item_no'] ?? '—' }}</td>
                            <td>{{ $part['supplier'] ?? '—' }}</td>
                            <td class="text-center"><span class="badge badge-secondary">{{ $part['side'] ?? 'COMMON' }}</span></td>
                            <td class="text-center">{{ $part['required_qty'] ?? 0 }}</td>
                            <td class="text-center text-success fw-bold">{{ $part['received_qty'] ?? 0 }}</td>
                            <td class="text-center text-danger fw-bold">{{ $part['pending_qty'] ?? 0 }}</td>
                            <td class="text-center"><span class="badge badge-primary">{{ $part['status_badge'] ?? 'Store' }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    <!-- Footer -->
    <table class="footer-table">
        <tr>
            <td>FAITH AUTOMATION &bull; SpareTrack Manufacturing Execution System</td>
            <td class="text-right">Confidential &bull; Industrial Engineering Dashboard Export</td>
        </tr>
    </table>
</body>
</html>
