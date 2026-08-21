<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'FAITH AUTOMATION — Dashboard Report' }}</title>
    <style>
        @page {
            margin: 12mm 10mm 12mm 10mm;
            size: a4 landscape;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 9px;
            color: #1e293b;
            line-height: 1.3;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 6px;
        }
        .header-table td {
            vertical-align: middle;
        }
        .brand-title {
            font-size: 15px;
            font-weight: bold;
            color: #0f172a;
            letter-spacing: 0.5px;
        }
        .report-subtitle {
            font-size: 11px;
            color: #2563eb;
            font-weight: 600;
            margin-top: 1px;
        }
        .meta-box {
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 5px 8px;
            margin-bottom: 10px;
            font-size: 8.5px;
        }
        .meta-item {
            display: inline-block;
            margin-right: 15px;
        }
        .meta-label {
            font-weight: bold;
            color: #475569;
        }
        .meta-val {
            color: #0f172a;
        }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #0f172a;
            margin-top: 10px;
            margin-bottom: 4px;
            border-bottom: 1.5px solid #cbd5e1;
            padding-bottom: 2px;
        }
        .kpi-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .kpi-card {
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 6px 8px;
            text-align: center;
            vertical-align: top;
            background-color: #ffffff;
        }
        .kpi-title {
            font-size: 8px;
            text-transform: uppercase;
            color: #64748b;
            font-weight: bold;
            margin-bottom: 2px;
        }
        .kpi-value {
            font-size: 14px;
            font-weight: bold;
            color: #0f172a;
        }
        .kpi-sub {
            font-size: 7.5px;
            color: #64748b;
            margin-top: 2px;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
            margin-bottom: 10px;
        }
        .data-table th, .data-table td {
            border: 1px solid #cbd5e1;
            padding: 4px 5px;
            text-align: left;
            font-size: 8.5px;
        }
        .data-table th {
            background-color: #0f172a;
            color: #ffffff;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 8px;
            letter-spacing: 0.3px;
        }
        .data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .badge {
            display: inline-block;
            padding: 1px 4px;
            font-size: 7.5px;
            font-weight: bold;
            border-radius: 3px;
            text-align: center;
        }
        .badge-primary { background-color: #2563eb; color: #fff; }
        .badge-success { background-color: #10b981; color: #fff; }
        .badge-danger  { background-color: #ef4444; color: #fff; }
        .badge-warning { background-color: #f59e0b; color: #fff; }
        .badge-secondary { background-color: #64748b; color: #fff; }
        .badge-purple  { background-color: #8b5cf6; color: #fff; }
        .badge-teal    { background-color: #0d9488; color: #fff; }

        .progress-bar-bg {
            background-color: #e2e8f0;
            border-radius: 2px;
            height: 6px;
            width: 80px;
            display: inline-block;
            vertical-align: middle;
        }
        .progress-bar-fill {
            background-color: #10b981;
            height: 6px;
            border-radius: 2px;
        }
        .footer-table {
            position: fixed;
            bottom: -8mm;
            left: 0;
            right: 0;
            width: 100%;
            border-top: 1px solid #e2e8f0;
            padding-top: 3px;
            font-size: 7.5px;
            color: #64748b;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .fw-bold { font-weight: bold; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    <!-- Header Banner -->
    <table class="header-table">
        <tr>
            <td>
                <div class="brand-title">FAITH AUTOMATION — Industrial Spare Parts Tracking System</div>
                <div class="report-subtitle">Manufacturing Manager Terminal — Dashboard Executive Report</div>
            </td>
            <td class="text-right">
                <div style="font-size: 8px; color: #64748b;">
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
            <span class="meta-val">{{ $active_projects_count ?? 0 }}</span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Overall Completion:</span>
            <span class="meta-val fw-bold text-success">{{ $summary['completion_pct'] ?? 0 }}%</span>
        </div>
    </div>

    <!-- 1. PRIMARY MANAGEMENT KPI SUMMARY -->
    <div class="section-title">1. EXECUTIVE KPI &amp; OPERATIONAL SUMMARY</div>
    <table class="kpi-table">
        <tr>
            @if(empty($project_id))
                <td class="kpi-card" style="width: 33.3%;">
                    <div class="kpi-title" style="color: #2563eb;">Active Projects</div>
                    <div class="kpi-value" style="color: #2563eb;">{{ $summary['active_projects'] ?? 0 }}</div>
                    <div class="kpi-sub">Portfolio In-Progress</div>
                </td>
                <td class="kpi-card" style="width: 33.3%;">
                    <div class="kpi-title" style="color: #10b981;">Completed Projects</div>
                    <div class="kpi-value" style="color: #10b981;">{{ $summary['completed_projects'] ?? 0 }}</div>
                    <div class="kpi-sub">100% Assembled</div>
                </td>
                <td class="kpi-card" style="width: 33.3%;">
                    <div class="kpi-title" style="color: #ef4444;">Delayed Projects</div>
                    <div class="kpi-value" style="color: #ef4444;">{{ $summary['delayed_projects'] ?? 0 }}</div>
                    <div class="kpi-sub">>14d Inactive &amp; &lt;80%</div>
                </td>
            @else
                <td class="kpi-card" style="width: 25%;">
                    <div class="kpi-title" style="color: #2563eb;">Selected Project</div>
                    <div class="kpi-value" style="font-size: 11px;">{{ $project_name ?? 'Project' }}</div>
                    <div class="kpi-sub">{{ $project_code ?? '' }}</div>
                </td>
                <td class="kpi-card" style="width: 25%;">
                    <div class="kpi-title" style="color: #475569;">Total Jigs / Units</div>
                    <div class="kpi-value">{{ $summary['total_jigs'] ?? 0 }} / {{ $summary['total_units'] ?? 0 }}</div>
                    <div class="kpi-sub">Production Units</div>
                </td>
                <td class="kpi-card" style="width: 25%;">
                    <div class="kpi-title" style="color: #10b981;">Completion Rate</div>
                    <div class="kpi-value" style="color: #10b981;">{{ $summary['completion_pct'] ?? 0 }}%</div>
                    <div class="kpi-sub">{{ $summary['total_received'] ?? 0 }} / {{ $summary['total_bom_parts'] ?? 0 }} Pcs</div>
                </td>
                <td class="kpi-card" style="width: 25%;">
                    <div class="kpi-title" style="color: #ef4444;">Pending Parts</div>
                    <div class="kpi-value" style="color: #ef4444;">{{ $summary['parts_pending'] ?? 0 }}</div>
                    <div class="kpi-sub">To Complete Project</div>
                </td>
            @endif
        </tr>
    </table>

    <!-- Workstation Operational KPI Grid (8 Stages) -->
    <table class="kpi-table" style="margin-top: 6px;">
        <tr>
            <td class="kpi-card">
                <div class="kpi-title">Total BOM Parts</div>
                <div class="kpi-value">{{ $summary['total_bom_parts'] ?? 0 }}</div>
                <div class="kpi-sub">Received: {{ $summary['total_received'] ?? 0 }} | Pend: {{ $summary['parts_pending'] ?? 0 }}</div>
            </td>
            <td class="kpi-card">
                <div class="kpi-title">Store Intake</div>
                <div class="kpi-value" style="color: #d97706;">{{ $summary['parts_in_store'] ?? 0 }}</div>
                <div class="kpi-sub">In Store Queue</div>
            </td>
            <td class="kpi-card">
                <div class="kpi-title">QC Department</div>
                <div class="kpi-value" style="color: #0284c7;">{{ $summary['qc_inspections'] ?? 0 }}</div>
                <div class="kpi-sub">Approved: {{ $summary['qc_approved'] ?? 0 }} | Rej: {{ $summary['qc_rejected'] ?? 0 }}</div>
            </td>
            <td class="kpi-card">
                <div class="kpi-title">Rework Queue</div>
                <div class="kpi-value" style="color: #dc2626;">{{ $summary['rework_queue'] ?? 0 }}</div>
                <div class="kpi-sub">In Rework Process</div>
            </td>
            <td class="kpi-card">
                <div class="kpi-title">Purchase Queue</div>
                <div class="kpi-value" style="color: #ea580c;">{{ $summary['purchase_queue'] ?? 0 }}</div>
                <div class="kpi-sub">Reorder Queue Items</div>
            </td>
            <td class="kpi-card">
                <div class="kpi-title">Paint Shop</div>
                <div class="kpi-value" style="color: #7c3aed;">{{ $summary['parts_in_paint'] ?? 0 }}</div>
                <div class="kpi-sub">Painted: {{ $summary['paint_completed'] ?? 0 }}</div>
            </td>
            <td class="kpi-card">
                <div class="kpi-title">Assembly Shop</div>
                <div class="kpi-value" style="color: #db2777;">{{ $summary['parts_in_assembly'] ?? 0 }}</div>
                <div class="kpi-sub">Completed: {{ $summary['assembly_completed'] ?? 0 }}</div>
            </td>
        </tr>
    </table>

    <!-- 2. TOP PROJECTS NEAR COMPLETION & HEALTH DISTRIBUTION ROW -->
    <table style="width: 100%; border-collapse: collapse; margin-top: 8px;">
        <tr>
            <!-- Left Column: Top Projects Near Completion -->
            <td style="width: 58%; vertical-align: top; padding-right: 8px;">
                <div class="section-title">2. TOP PROJECTS NEAR COMPLETION</div>
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
                                    <span style="color: #64748b; font-size: 7.5px;">({{ $proj['code'] }})</span>
                                </td>
                                <td class="text-center">{{ $proj['required'] }}</td>
                                <td class="text-center" style="color: #10b981; font-weight: bold;">{{ $proj['received'] }}</td>
                                <td class="text-center" style="color: #ef4444; font-weight: bold;">{{ $proj['pending'] }}</td>
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
            <td style="width: 42%; vertical-align: top; padding-left: 8px;">
                <div class="section-title">3. PROJECT HEALTH DISTRIBUTION</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Health Classification</th>
                            <th style="text-align: center; width: 25%;">Count</th>
                            <th style="text-align: center; width: 25%;">Share %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="badge badge-success">Near Completion</span> <span style="font-size: 7.5px; color: #64748b;">(≥85% Complete)</span></td>
                            <td class="text-center fw-bold">{{ $health_counts['near_completion'] ?? 0 }}</td>
                            <td class="text-center">{{ $health_pcts['near_completion'] ?? 0 }}%</td>
                        </tr>
                        <tr>
                            <td><span class="badge badge-primary">On Track</span> <span style="font-size: 7.5px; color: #64748b;">(Active last 7 days)</span></td>
                            <td class="text-center fw-bold">{{ $health_counts['on_track'] ?? 0 }}</td>
                            <td class="text-center">{{ $health_pcts['on_track'] ?? 0 }}%</td>
                        </tr>
                        <tr>
                            <td><span class="badge badge-warning">At Risk</span> <span style="font-size: 7.5px; color: #64748b;">(No activity 7–14 days)</span></td>
                            <td class="text-center fw-bold">{{ $health_counts['at_risk'] ?? 0 }}</td>
                            <td class="text-center">{{ $health_pcts['at_risk'] ?? 0 }}%</td>
                        </tr>
                        <tr>
                            <td><span class="badge badge-danger">Delayed</span> <span style="font-size: 7.5px; color: #64748b;">(>14d inactive &amp; &lt;80%)</span></td>
                            <td class="text-center fw-bold">{{ $health_counts['delayed'] ?? 0 }}</td>
                            <td class="text-center">{{ $health_pcts['delayed'] ?? 0 }}%</td>
                        </tr>
                        <tr style="background-color: #f1f5f9; font-weight: bold;">
                            <td>Total Active Projects Evaluated</td>
                            <td class="text-center">{{ $health_total ?? 0 }}</td>
                            <td class="text-center">100%</td>
                        </tr>
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <!-- 3. PROJECT HIERARCHY OR PORTFOLIO BREAKDOWN (Page 2) -->
    @if(!empty($jigs) && count($jigs) > 0)
        <div class="page-break"></div>

        <!-- Second Page Header -->
        <table class="header-table">
            <tr>
                <td>
                    <div class="brand-title">FAITH AUTOMATION — Project Hierarchy Breakdown</div>
                    <div class="report-subtitle">{{ $scope_label }} — Level 3 Jigs &amp; Units Detail</div>
                </td>
                <td class="text-right">
                    <div style="font-size: 8px; color: #64748b;">
                        <strong>Generated:</strong> {{ $generated_at }}
                    </div>
                </td>
            </tr>
        </table>

        <div class="section-title">4. JIG &amp; PRODUCTION UNIT HIERARCHY DETAIL</div>
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
            <div class="section-title" style="margin-top: 12px;">5. PART INVENTORY SAMPLE DETAIL</div>
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
