<?php

namespace App\Services;

use App\Models\Project;
use App\Models\BomItem;
use App\Services\QuantityCalculationService;
use App\Services\HierarchyService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportService
{
    protected QuantityCalculationService $quantityService;
    protected HierarchyService $hierarchyService;

    public function __construct(
        ?QuantityCalculationService $quantityService = null,
        ?HierarchyService $hierarchyService = null
    ) {
        $this->quantityService = $quantityService ?? new QuantityCalculationService();
        $this->hierarchyService = $hierarchyService ?? new HierarchyService($this->quantityService);
    }

    /**
     * Build export payload specifically for Parts Movement Detail View.
     */
    public function exportMovementData(Request $request): array
    {
        $dateLabel = $request->input('date_label', now()->format('d-M-y'));
        $department = $request->input('department', 'All Departments');
        $rawItems = $request->input('items', []);

        $activeFilters = [];
        if ($department && $department !== 'All Departments') {
            $activeFilters[] = "Department: {$department}";
        }
        $colFilters = $request->input('column_filters', []);
        if (is_array($colFilters)) {
            foreach ($colFilters as $k => $v) {
                if (!empty($v)) {
                    $activeFilters[] = ucfirst($k) . ": {$v}";
                }
            }
        }
        $activeFiltersStr = !empty($activeFilters) ? implode(' | ', $activeFilters) : 'All Movements';

        $columns = [
            ['label' => 'Part Number', 'key' => 'part_no', 'width' => '16%'],
            ['label' => 'Project', 'key' => 'project', 'width' => '12%'],
            ['label' => 'Side', 'key' => 'side', 'width' => '8%', 'align' => 'center'],
            ['label' => 'Qty', 'key' => 'qty', 'width' => '8%', 'align' => 'center'],
            ['label' => 'Department Movement', 'key' => 'event', 'width' => '22%'],
            ['label' => 'Processed By', 'key' => 'user', 'width' => '14%'],
            ['label' => 'Date', 'key' => 'date', 'width' => '10%', 'align' => 'center'],
            ['label' => 'Time', 'key' => 'time', 'width' => '10%', 'align' => 'center'],
        ];

        $rows = [];
        foreach ($rawItems as $item) {
            $rows[] = [
                'part_no' => $item['standard_part_no'] ?? $item['part_no'] ?? 'N/A',
                'project' => $item['project'] ?? 'N/A',
                'side' => $item['side'] ?? 'COMMON',
                'qty' => $item['quantity'] ?? $item['qty'] ?? 1,
                'event' => $item['department_event'] ?? $item['event'] ?? 'MOVEMENT',
                'user' => $item['user'] ?? 'User',
                'date' => $item['date'] ?? $dateLabel,
                'time' => $item['time'] ?? '—',
                'color' => 'primary',
            ];
        }

        $timestamp = now()->format('Ymd_His');
        $filename = "SpareTrack_PartsMovement_{$dateLabel}_{$timestamp}";

        return [
            'title' => "Parts Movement Detail — {$dateLabel}",
            'section_name' => "Parts_Movement_{$dateLabel}",
            'date_range' => $dateLabel,
            'active_filters' => $activeFiltersStr,
            'generated_at' => now()->format('d-M-Y H:i:s T'),
            'generated_by' => $request->user()?->name ?? 'FAITH AUTOMATION User',
            'filename' => $filename,
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * Build comprehensive Dashboard export dataset using canonical calculation services.
     */
    public function exportDashboardData(Request $request): array
    {
        $projectId = $request->input('project_id') ? (int) $request->input('project_id') : null;
        $filters = ['project_id' => $projectId];

        // 1. Authoritative canonical calculations from QuantityCalculationService
        $summary = $this->quantityService->calculateDashboardSummary($filters);
        $topProjectsRaw = $this->quantityService->getTopProjectsNearCompletion($filters);
        $healthRaw = $this->quantityService->calculateProjectHealthDistribution($filters);
        $projectsProgress = $this->quantityService->calculateProjectsProgress($filters);
        $hierarchy = $this->hierarchyService->getDepartmentHierarchy('manager', $projectId);

        // 2. Format Top Projects Near Completion list
        $topProjectsList = [];
        if (!empty($topProjectsRaw['projects'])) {
            foreach ($topProjectsRaw['projects'] as $p) {
                $topProjectsList[] = [
                    'code' => $p['project_code'] ?? $p['name'] ?? '',
                    'name' => $p['name'] ?? '',
                    'customer' => $p['customer'] ?? '—',
                    'required' => $p['total_required'] ?? 0,
                    'received' => $p['total_received'] ?? 0,
                    'pending' => $p['total_pending'] ?? 0,
                    'percentage' => $p['completion_pct'] ?? 0,
                    'status' => ($p['completion_pct'] >= 85) ? 'Near Completion' : (($p['completion_pct'] >= 50) ? 'On Track' : 'In Progress'),
                ];
            }
        } elseif (!empty($topProjectsRaw['labels'])) {
            for ($i = 0; $i < count($topProjectsRaw['labels']); $i++) {
                $topProjectsList[] = [
                    'code' => $topProjectsRaw['labels'][$i] ?? '',
                    'name' => $topProjectsRaw['names'][$i] ?? $topProjectsRaw['labels'][$i] ?? '',
                    'customer' => '—',
                    'required' => $topProjectsRaw['required'][$i] ?? 0,
                    'received' => $topProjectsRaw['received'][$i] ?? 0,
                    'pending' => $topProjectsRaw['pending'][$i] ?? 0,
                    'percentage' => $topProjectsRaw['percentages'][$i] ?? 0,
                    'status' => ($topProjectsRaw['percentages'][$i] >= 85) ? 'Near Completion' : (($topProjectsRaw['percentages'][$i] >= 50) ? 'On Track' : 'In Progress'),
                ];
            }
        }

        // 3. Project Health Breakdown
        $healthCounts = $healthRaw['counts'] ?? ['near_completion' => 0, 'on_track' => 0, 'at_risk' => 0, 'delayed' => 0];
        $healthPcts = $healthRaw['percentages'] ?? ['near_completion' => 0, 'on_track' => 0, 'at_risk' => 0, 'delayed' => 0];
        $healthTotal = $healthRaw['total_active'] ?? 0;

        // 4. Project metadata & scope
        $projectName = null;
        $projectCode = null;
        $scopeLabel = 'All Active Projects';
        if ($projectId) {
            $projModel = Project::find($projectId);
            if ($projModel) {
                $projectName = $projModel->name;
                $projectCode = $projModel->project_code;
                $scopeLabel = "{$projModel->project_code} - {$projModel->name}";
            }
        }

        // 5. Jigs & Part Inventory sample for hierarchy
        $jigs = $hierarchy['jigs'] ?? [];
        $partsSample = [];
        if ($projectId) {
            $partsQuery = BomItem::query()
                ->with(['supplier', 'requirements'])
                ->where('project_id', $projectId)
                ->orderBy('standard_part_no')
                ->limit(100)
                ->get();

            foreach ($partsQuery as $pt) {
                $partsSample[] = [
                    'standard_part_no' => $pt->standard_part_no,
                    'item_no' => $pt->item_no,
                    'supplier' => $pt->supplier?->name ?? '—',
                    'side' => $pt->side ?? 'COMMON',
                    'required_qty' => $pt->total_required ?? 0,
                    'received_qty' => $pt->total_received ?? 0,
                    'pending_qty' => $pt->total_pending ?? 0,
                    'status_badge' => 'Store',
                ];
            }
        }

        $timestamp = now()->format('Ymd_His');
        $cleanScope = preg_replace('/[^A-Za-z0-9_\-]/', '_', $scopeLabel);
        $filename = "SpareTrack_Dashboard_{$cleanScope}_{$timestamp}";

        return [
            'title' => "Manufacturing Dashboard Executive Report — {$scopeLabel}",
            'project_id' => $projectId,
            'project_name' => $projectName,
            'project_code' => $projectCode,
            'scope_label' => $scopeLabel,
            'active_projects_count' => $healthTotal,
            'summary' => $summary,
            'top_projects_list' => $topProjectsList,
            'health_counts' => $healthCounts,
            'health_pcts' => $healthPcts,
            'health_total' => $healthTotal,
            'projects_progress' => $projectsProgress,
            'jigs' => $jigs,
            'parts_sample' => $partsSample,
            'generated_at' => now()->format('d-M-Y H:i:s T'),
            'generated_by' => $request->user()?->name ?? 'FAITH AUTOMATION User',
            'filename' => $filename,
        ];
    }

    /**
     * Generate Styled Multi-Sheet Excel (.xlsx) workbook for Dashboard using PhpSpreadsheet.
     */
    public function generateDashboardExcel(array $data): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();

        // -------------------------------------------------------------
        // SHEET 1: DASHBOARD SUMMARY
        // -------------------------------------------------------------
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Dashboard Summary');

        // Brand Banner
        $sheet1->setCellValue('A1', 'FAITH AUTOMATION — Industrial Spare Parts Tracking System');
        $sheet1->mergeCells('A1:G1');
        $sheet1->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new Color('0F172A'));
        $sheet1->getRowDimension(1)->setRowHeight(24);

        // Subtitle
        $sheet1->setCellValue('A2', 'Manufacturing Manager Terminal — Dashboard Executive Summary');
        $sheet1->mergeCells('A2:G2');
        $sheet1->getStyle('A2')->getFont()->setBold(true)->setSize(11)->setColor(new Color('2563EB'));

        // Metadata
        $sheet1->setCellValue('A3', 'Scope: ' . $data['scope_label'] . '   |   Generated: ' . $data['generated_at'] . '   |   By: ' . $data['generated_by']);
        $sheet1->mergeCells('A3:G3');
        $sheet1->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->setColor(new Color('64748B'));
        $sheet1->getRowDimension(3)->setRowHeight(18);

        // Section 1 Header: Primary KPI Cards
        $sheet1->setCellValue('A5', '1. EXECUTIVE KPI SUMMARY');
        $sheet1->mergeCells('A5:C5');
        $sheet1->getStyle('A5')->getFont()->setBold(true)->setSize(11)->setColor(new Color('0F172A'));

        $sheet1->setCellValue('A6', 'Metric Description');
        $sheet1->setCellValue('B6', 'Current Value');
        $sheet1->setCellValue('C6', 'Unit / Details');
        $sheet1->getStyle('A6:C6')->getFont()->setBold(true)->setColor(new Color('FFFFFF'));
        $sheet1->getStyle('A6:C6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F172A');

        $summaryRows = [];
        if (empty($data['project_id'])) {
            $summaryRows[] = ['Active Projects', $data['summary']['active_projects'] ?? 0, 'Projects In Progress'];
            $summaryRows[] = ['Completed Projects', $data['summary']['completed_projects'] ?? 0, '100% Assembled'];
            $summaryRows[] = ['Delayed Projects', $data['summary']['delayed_projects'] ?? 0, '>14d Inactive & <80% Complete'];
        } else {
            $summaryRows[] = ['Project Name', $data['project_name'] ?? '—', 'Selected Project'];
            $summaryRows[] = ['Project Code', $data['project_code'] ?? '—', 'Code'];
            $summaryRows[] = ['Total Jigs', $data['summary']['total_jigs'] ?? 0, 'Jigs'];
            $summaryRows[] = ['Total Units', $data['summary']['total_units'] ?? 0, 'Production Units'];
            $summaryRows[] = ['Overall Project Completion', ($data['summary']['completion_pct'] ?? 0) . '%', 'Completion Rate'];
        }
        $summaryRows[] = ['Total Required BOM Parts', $data['summary']['total_bom_parts'] ?? 0, 'Pieces Required'];
        $summaryRows[] = ['Total Received Parts', $data['summary']['total_received'] ?? 0, 'Pieces In-Plant'];
        $summaryRows[] = ['Parts Pending Intake', $data['summary']['parts_pending'] ?? 0, 'Pieces Missing'];
        $summaryRows[] = ['Overall Portfolio Completion', ($data['summary']['completion_pct'] ?? 0) . '%', 'Received / Required'];

        $rowIdx = 7;
        foreach ($summaryRows as $sr) {
            $sheet1->setCellValue('A' . $rowIdx, $sr[0]);
            $sheet1->setCellValue('B' . $rowIdx, $sr[1]);
            $sheet1->setCellValue('C' . $rowIdx, $sr[2]);
            if (($rowIdx - 7) % 2 === 0) {
                $sheet1->getStyle("A{$rowIdx}:C{$rowIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
            }
            $rowIdx++;
        }
        $sheet1->getStyle("A6:C" . ($rowIdx - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');

        // Section 2 Header: Workstation Operational Status
        $rowIdx += 2;
        $sheet1->setCellValue('A' . $rowIdx, '2. WORKSTATION OPERATIONAL STATUS');
        $sheet1->mergeCells("A{$rowIdx}:D{$rowIdx}");
        $sheet1->getStyle('A' . $rowIdx)->getFont()->setBold(true)->setSize(11)->setColor(new Color('0F172A'));
        $rowIdx++;

        $sheet1->setCellValue('A' . $rowIdx, 'Workstation / Department');
        $sheet1->setCellValue('B' . $rowIdx, 'Active Queue (pcs)');
        $sheet1->setCellValue('C' . $rowIdx, 'Completed / Approved (pcs)');
        $sheet1->setCellValue('D' . $rowIdx, 'Operational Notes');
        $sheet1->getStyle("A{$rowIdx}:D{$rowIdx}")->getFont()->setBold(true)->setColor(new Color('FFFFFF'));
        $sheet1->getStyle("A{$rowIdx}:D{$rowIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F172A');
        $opHeaderRow = $rowIdx;
        $rowIdx++;

        $opRows = [
            ['Store Intake', $data['summary']['parts_in_store'] ?? 0, $data['summary']['total_received'] ?? 0, 'Parts currently stored in warehouse intake'],
            ['QC Department', $data['summary']['qc_inspections'] ?? 0, $data['summary']['qc_approved'] ?? 0, 'Rejections: ' . ($data['summary']['qc_rejected'] ?? 0) . ' | Rework: ' . ($data['summary']['qc_rework'] ?? 0)],
            ['Rework Queue', $data['summary']['rework_queue'] ?? 0, $data['summary']['rework_completed'] ?? 0, 'Parts requiring machining/welding corrections'],
            ['Purchase Queue', $data['summary']['purchase_queue'] ?? 0, '—', 'Rejected pieces waiting for vendor reordering'],
            ['Paint Shop', $data['summary']['parts_in_paint'] ?? 0, $data['summary']['paint_completed'] ?? 0, 'Surface preparation, primer & topcoat'],
            ['Assembly Shop', $data['summary']['parts_in_assembly'] ?? 0, $data['summary']['assembly_completed'] ?? 0, 'Final jig mechanical assembly and testing'],
        ];

        foreach ($opRows as $opr) {
            $sheet1->setCellValue('A' . $rowIdx, $opr[0]);
            $sheet1->setCellValue('B' . $rowIdx, $opr[1]);
            $sheet1->setCellValue('C' . $rowIdx, $opr[2]);
            $sheet1->setCellValue('D' . $rowIdx, $opr[3]);
            if (($rowIdx - $opHeaderRow) % 2 === 0) {
                $sheet1->getStyle("A{$rowIdx}:D{$rowIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
            }
            $rowIdx++;
        }
        $sheet1->getStyle("A{$opHeaderRow}:D" . ($rowIdx - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $col) {
            $sheet1->getColumnDimension($col)->setAutoSize(true);
        }

        // -------------------------------------------------------------
        // SHEET 2: TOP PROJECTS NEAR COMPLETION
        // -------------------------------------------------------------
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Top Projects Completion');

        $sheet2->setCellValue('A1', 'TOP PROJECTS NEAR COMPLETION — ACTIVE PORTFOLIO');
        $sheet2->mergeCells('A1:G1');
        $sheet2->getStyle('A1')->getFont()->setBold(true)->setSize(12)->setColor(new Color('0F172A'));

        $sheet2->setCellValue('A3', 'Rank');
        $sheet2->setCellValue('B3', 'Project Code');
        $sheet2->setCellValue('C3', 'Project Name');
        $sheet2->setCellValue('D3', 'Required (pcs)');
        $sheet2->setCellValue('E3', 'Received (pcs)');
        $sheet2->setCellValue('F3', 'Pending (pcs)');
        $sheet2->setCellValue('G3', 'Completion %');
        $sheet2->setCellValue('H3', 'Status');
        $sheet2->getStyle('A3:H3')->getFont()->setBold(true)->setColor(new Color('FFFFFF'));
        $sheet2->getStyle('A3:H3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F172A');

        $pRow = 4;
        $rank = 1;
        foreach ($data['top_projects_list'] as $tp) {
            $sheet2->setCellValue('A' . $pRow, $rank++);
            $sheet2->setCellValue('B' . $pRow, $tp['code']);
            $sheet2->setCellValue('C' . $pRow, $tp['name']);
            $sheet2->setCellValue('D' . $pRow, $tp['required']);
            $sheet2->setCellValue('E' . $pRow, $tp['received']);
            $sheet2->setCellValue('F' . $pRow, $tp['pending']);
            $sheet2->setCellValue('G' . $pRow, ($tp['percentage'] / 100));
            $sheet2->getStyle('G' . $pRow)->getNumberFormat()->setFormatCode('0.0%');
            $sheet2->setCellValue('H' . $pRow, $tp['status']);

            if ($pRow % 2 === 0) {
                $sheet2->getStyle("A{$pRow}:H{$pRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
            }
            $pRow++;
        }
        $sheet2->getStyle("A3:H" . max($pRow - 1, 3))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $col) {
            $sheet2->getColumnDimension($col)->setAutoSize(true);
        }

        // -------------------------------------------------------------
        // SHEET 3: PROJECT HEALTH DISTRIBUTION
        // -------------------------------------------------------------
        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('Project Health Distribution');

        $sheet3->setCellValue('A1', 'EXECUTIVE PROJECT HEALTH DISTRIBUTION');
        $sheet3->mergeCells('A1:D1');
        $sheet3->getStyle('A1')->getFont()->setBold(true)->setSize(12)->setColor(new Color('0F172A'));

        $sheet3->setCellValue('A3', 'Health Category');
        $sheet3->setCellValue('B3', 'Evaluation Criteria');
        $sheet3->setCellValue('C3', 'Active Projects Count');
        $sheet3->setCellValue('D3', 'Percentage Share');
        $sheet3->getStyle('A3:D3')->getFont()->setBold(true)->setColor(new Color('FFFFFF'));
        $sheet3->getStyle('A3:D3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F172A');

        $healthDataRows = [
            ['Near Completion', '≥85% BOM Parts Complete', $data['health_counts']['near_completion'] ?? 0, ($data['health_pcts']['near_completion'] ?? 0) / 100],
            ['On Track', 'Active progress logged in last 7 days', $data['health_counts']['on_track'] ?? 0, ($data['health_pcts']['on_track'] ?? 0) / 100],
            ['At Risk', 'No operational activity for 7–14 days', $data['health_counts']['at_risk'] ?? 0, ($data['health_pcts']['at_risk'] ?? 0) / 100],
            ['Delayed', 'No activity for >14 days & <80% complete', $data['health_counts']['delayed'] ?? 0, ($data['health_pcts']['delayed'] ?? 0) / 100],
            ['Total Active Projects', 'All Evaluated Active Projects', $data['health_total'] ?? 0, 1.0],
        ];

        $hRow = 4;
        foreach ($healthDataRows as $hdr) {
            $sheet3->setCellValue('A' . $hRow, $hdr[0]);
            $sheet3->setCellValue('B' . $hRow, $hdr[1]);
            $sheet3->setCellValue('C' . $hRow, $hdr[2]);
            $sheet3->setCellValue('D' . $hRow, $hdr[3]);
            $sheet3->getStyle('D' . $hRow)->getNumberFormat()->setFormatCode('0.0%');

            if ($hRow === 8) {
                $sheet3->getStyle("A{$hRow}:D{$hRow}")->getFont()->setBold(true);
                $sheet3->getStyle("A{$hRow}:D{$hRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
            } elseif ($hRow % 2 === 0) {
                $sheet3->getStyle("A{$hRow}:D{$hRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
            }
            $hRow++;
        }
        $sheet3->getStyle("A3:D" . ($hRow - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');

        foreach (['A', 'B', 'C', 'D'] as $col) {
            $sheet3->getColumnDimension($col)->setAutoSize(true);
        }

        // -------------------------------------------------------------
        // SHEET 4: PROJECT OVERVIEW & JIG HIERARCHY
        // -------------------------------------------------------------
        $sheet4 = $spreadsheet->createSheet();
        $sheet4->setTitle('Project Hierarchy & Jigs');

        if (!empty($data['jigs']) && count($data['jigs']) > 0) {
            $sheet4->setCellValue('A1', 'JIG BREAKDOWN — ' . $data['scope_label']);
            $sheet4->mergeCells('A1:G1');
            $sheet4->getStyle('A1')->getFont()->setBold(true)->setSize(12)->setColor(new Color('0F172A'));

            $sheet4->setCellValue('A3', 'Jig Name');
            $sheet4->setCellValue('B3', 'Total Units');
            $sheet4->setCellValue('C3', 'Required (pcs)');
            $sheet4->setCellValue('D3', 'Received (pcs)');
            $sheet4->setCellValue('E3', 'Pending (pcs)');
            $sheet4->setCellValue('F3', 'Completion %');
            $sheet4->setCellValue('G3', 'Status');
            $sheet4->getStyle('A3:G3')->getFont()->setBold(true)->setColor(new Color('FFFFFF'));
            $sheet4->getStyle('A3:G3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F172A');

            $jRow = 4;
            foreach ($data['jigs'] as $jig) {
                $sheet4->setCellValue('A' . $jRow, $jig['jig_name']);
                $sheet4->setCellValue('B' . $jRow, count($jig['units'] ?? []));
                $sheet4->setCellValue('C' . $jRow, $jig['total_required'] ?? 0);
                $sheet4->setCellValue('D' . $jRow, $jig['total_received'] ?? 0);
                $sheet4->setCellValue('E' . $jRow, $jig['pending_quantity'] ?? 0);
                $sheet4->setCellValue('F' . $jRow, ($jig['completion_pct'] ?? 0) / 100);
                $sheet4->getStyle('F' . $jRow)->getNumberFormat()->setFormatCode('0.0%');
                $sheet4->setCellValue('G' . $jRow, !empty($jig['is_complete']) ? 'Completed' : 'In Progress');

                if ($jRow % 2 === 0) {
                    $sheet4->getStyle("A{$jRow}:G{$jRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
                }
                $jRow++;
            }
            $sheet4->getStyle("A3:G" . max($jRow - 1, 3))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');
        } else {
            $sheet4->setCellValue('A1', 'PORTFOLIO PROJECTS BREAKDOWN');
            $sheet4->mergeCells('A1:F1');
            $sheet4->getStyle('A1')->getFont()->setBold(true)->setSize(12)->setColor(new Color('0F172A'));

            $sheet4->setCellValue('A3', 'Project Code');
            $sheet4->setCellValue('B3', 'Project Name');
            $sheet4->setCellValue('C3', 'Required (pcs)');
            $sheet4->setCellValue('D3', 'Received (pcs)');
            $sheet4->setCellValue('E3', 'Pending (pcs)');
            $sheet4->setCellValue('F3', 'Completion %');
            $sheet4->getStyle('A3:F3')->getFont()->setBold(true)->setColor(new Color('FFFFFF'));
            $sheet4->getStyle('A3:F3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F172A');

            $jRow = 4;
            foreach ($data['projects_progress'] as $pp) {
                $sheet4->setCellValue('A' . $jRow, $pp['project_code'] ?? '');
                $sheet4->setCellValue('B' . $jRow, $pp['name'] ?? '');
                $sheet4->setCellValue('C' . $jRow, $pp['total_required'] ?? 0);
                $sheet4->setCellValue('D' . $jRow, $pp['total_received'] ?? 0);
                $sheet4->setCellValue('E' . $jRow, $pp['total_pending'] ?? 0);
                $sheet4->setCellValue('F' . $jRow, ($pp['completion_pct'] ?? 0) / 100);
                $sheet4->getStyle('F' . $jRow)->getNumberFormat()->setFormatCode('0.0%');

                if ($jRow % 2 === 0) {
                    $sheet4->getStyle("A{$jRow}:F{$jRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
                }
                $jRow++;
            }
            $sheet4->getStyle("A3:F" . max($jRow - 1, 3))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $col) {
            $sheet4->getColumnDimension($col)->setAutoSize(true);
        }

        // -------------------------------------------------------------
        // SHEET 5: PART INVENTORY DETAIL (If Single Project Selected)
        // -------------------------------------------------------------
        if (!empty($data['parts_sample']) && count($data['parts_sample']) > 0) {
            $sheet5 = $spreadsheet->createSheet();
            $sheet5->setTitle('Part Inventory Detail');

            $sheet5->setCellValue('A1', 'PART INVENTORY — ' . $data['scope_label']);
            $sheet5->mergeCells('A1:H1');
            $sheet5->getStyle('A1')->getFont()->setBold(true)->setSize(12)->setColor(new Color('0F172A'));

            $sheet5->setCellValue('A3', 'Standard Part No');
            $sheet5->setCellValue('B3', 'Item No');
            $sheet5->setCellValue('C3', 'Supplier');
            $sheet5->setCellValue('D3', 'Side');
            $sheet5->setCellValue('E3', 'Required Qty');
            $sheet5->setCellValue('F3', 'Received Qty');
            $sheet5->setCellValue('G3', 'Pending Qty');
            $sheet5->setCellValue('H3', 'Status');
            $sheet5->getStyle('A3:H3')->getFont()->setBold(true)->setColor(new Color('FFFFFF'));
            $sheet5->getStyle('A3:H3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F172A');

            $ptRow = 4;
            foreach ($data['parts_sample'] as $pt) {
                $sheet5->setCellValue('A' . $ptRow, $pt['standard_part_no']);
                $sheet5->setCellValue('B' . $ptRow, $pt['item_no'] ?? '—');
                $sheet5->setCellValue('C' . $ptRow, $pt['supplier'] ?? '—');
                $sheet5->setCellValue('D' . $ptRow, $pt['side'] ?? 'COMMON');
                $sheet5->setCellValue('E' . $ptRow, $pt['required_qty'] ?? 0);
                $sheet5->setCellValue('F' . $ptRow, $pt['received_qty'] ?? 0);
                $sheet5->setCellValue('G' . $ptRow, $pt['pending_qty'] ?? 0);
                $sheet5->setCellValue('H' . $ptRow, $pt['status_badge'] ?? 'Store');

                if ($ptRow % 2 === 0) {
                    $sheet5->getStyle("A{$ptRow}:H{$ptRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
                }
                $ptRow++;
            }
            $sheet5->getStyle("A3:H" . max($ptRow - 1, 3))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');

            foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $col) {
                $sheet5->getColumnDimension($col)->setAutoSize(true);
            }
        }

        // Set active sheet back to Sheet 1
        $spreadsheet->setActiveSheetIndex(0);

        $filename = $data['filename'] . '.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Generate Styled Landscape PDF download for Dashboard using DomPDF.
     */
    public function generateDashboardPdf(array $data)
    {
        $pdf = Pdf::loadView('exports.dashboard_pdf', $data)
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => 'sans-serif',
            ]);

        $filename = $data['filename'] . '.pdf';
        return $pdf->download($filename);
    }

    /**
     * Generate Styled Landscape PDF download using DomPDF.
     */
    public function generatePdf(array $data)
    {
        $pdf = Pdf::loadView('exports.universal_pdf', $data)
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => 'sans-serif',
            ]);

        $filename = $data['filename'] . '.pdf';
        return $pdf->download($filename);
    }

    /**
     * Generate Styled Excel (.xlsx) file download using PhpSpreadsheet.
     */
    public function generateExcel(array $data): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($data['section_name'], 0, 30));

        // 1. Company Brand Banner
        $sheet->setCellValue('A1', 'FAITH AUTOMATION — Industrial Spare Parts Tracking System');
        $sheet->mergeCells('A1:H1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new Color('0F172A'));
        $sheet->getRowDimension(1)->setRowHeight(24);

        // 2. Report Subtitle
        $sheet->setCellValue('A2', $data['title']);
        $sheet->mergeCells('A2:H2');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11)->setColor(new Color('2563EB'));

        // 3. Metadata Box
        $sheet->setCellValue('A3', 'Date: ' . $data['date_range'] . '   |   Filters: ' . $data['active_filters'] . '   |   Generated: ' . $data['generated_at'] . '   |   By: ' . $data['generated_by']);
        $sheet->mergeCells('A3:H3');
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->setColor(new Color('64748B'));
        $sheet->getRowDimension(3)->setRowHeight(18);

        // Blank separator row 4
        $startRow = 5;

        // 4. Table Headers
        $colIndex = 'A';
        foreach ($data['columns'] as $col) {
            $sheet->setCellValue($colIndex . $startRow, $col['label']);
            $colIndex++;
        }
        $lastCol = chr(ord('A') + count($data['columns']) - 1);

        $headerRange = "A{$startRow}:{$lastCol}{$startRow}";
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->setColor(new Color('FFFFFF'));
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F172A');
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($startRow)->setRowHeight(22);

        // 5. Data Rows
        $currentRow = $startRow + 1;
        foreach ($data['rows'] as $row) {
            $colIndex = 'A';
            foreach ($data['columns'] as $col) {
                $val = $row[$col['key']] ?? '';
                $sheet->setCellValue($colIndex . $currentRow, $val);
                if (isset($col['align']) && $col['align'] === 'center') {
                    $sheet->getStyle($colIndex . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }
                $colIndex++;
            }

            // Zebra striping
            if (($currentRow - $startRow) % 2 === 0) {
                $sheet->getStyle("A{$currentRow}:{$lastCol}{$currentRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
            }
            $sheet->getRowDimension($currentRow)->setRowHeight(18);
            $currentRow++;
        }

        // 6. Borders & Auto-column sizing
        $tableRange = "A{$startRow}:{$lastCol}" . max($currentRow - 1, $startRow);
        $sheet->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');

        $colChar = 'A';
        foreach ($data['columns'] as $col) {
            $sheet->getColumnDimension($colChar)->setAutoSize(true);
            $colChar++;
        }

        $filename = $data['filename'] . '.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
