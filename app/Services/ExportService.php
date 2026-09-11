<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\Project;
use App\Models\BomItem;
use App\Models\ReceiptItem;
use App\Models\SupplierAssignment;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ExportService
{
    protected HierarchyService $hierarchyService;

    public function __construct(?HierarchyService $hierarchyService = null)
    {
        $this->hierarchyService = $hierarchyService ?? app(HierarchyService::class);
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
     * Build export payload for Dashboard KPI Drill-down View.
     */
    public function exportKpiDrilldownData(Request $request): array
    {
        $kpiKey = $request->input('kpi', 'total_parts');
        $kpiNames = [
            'active_projects' => 'Active Projects',
            'completed_projects' => 'Completed Projects',
            'delayed_projects' => 'Delayed Projects',
            'total_parts' => 'Total Parts',
            'total_parts_received' => 'Total Parts Received',
            'parts_pending' => 'Parts Pending',
            'store' => 'Store Inventory',
            'qc' => 'QC Bay Parts',
            'rework' => 'Rework Queue',
            'paint' => 'Paint Shop Parts',
            'assembly' => 'Assembly Bay Parts',
        ];
        $kpiDisplayName = $kpiNames[$kpiKey] ?? ucwords(str_replace('_', ' ', $kpiKey));

        $filters = [
            'project_id' => $request->input('project_id'),
            'side' => $request->input('side'),
            'substate' => $request->input('substate', 'all'),
            'search' => $request->input('search'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'supplier_id' => $request->input('supplier_id'),
        ];

        $drilldownService = new KpiDrilldownService();
        // Fetch all matching rows (large per_page to retrieve all dataset items)
        $drilldown = $drilldownService->getDrilldownData($kpiKey, $filters, 1, 100000);

        $activeFilters = [];
        $activeFilters[] = "KPI: {$kpiDisplayName}";
        $activeFilters[] = "Scope: " . ($drilldown['project_scope'] ?? 'All Active Projects');
        if (!empty($filters['side'])) {
            $activeFilters[] = "Side: {$filters['side']}";
        }
        if (!empty($filters['substate']) && $filters['substate'] !== 'all') {
            $activeFilters[] = "Substate: " . ucfirst($filters['substate']);
        }
        if (!empty($filters['search'])) {
            $activeFilters[] = "Search: '{$filters['search']}'";
        }
        $activeFiltersStr = implode(' | ', $activeFilters);

        $scopeClean = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '_', $drilldown['project_scope'] ?? 'All_Projects'));
        $timestamp = now()->format('Ymd_His');
        $filename = "SpareTrack_{$kpiKey}_{$scopeClean}_{$timestamp}";

        // Configure Excel columns
        $isEcn = ($drilldown['is_ecn'] ?? false) || $kpiKey === 'ecn' || str_starts_with($kpiKey, 'ecn_');
        $columns = $drilldown['columns'];

        if ($isEcn) {
            $columns = [
                ['label' => 'Project', 'key' => 'project_code'],
                ['label' => 'ECN Number', 'key' => 'ecn_number'],
                ['label' => 'Jig No', 'key' => 'jig_no'],
                ['label' => 'Unit No', 'key' => 'unit_no', 'align' => 'center'],
                ['label' => 'Part Number', 'key' => 'part_no'],
                ['label' => 'Side', 'key' => 'side', 'align' => 'center'],
                ['label' => 'Combined Identifier', 'key' => 'combined_identifier'],
                ['label' => 'Status', 'key' => 'status'],
                ['label' => 'Quantity', 'key' => 'quantity', 'align' => 'center'],
            ];
        } elseif (($drilldown['kpi_type'] ?? 'part') === 'part') {
            $columns = [
                ['label' => 'Project', 'key' => 'project_code'],
                ['label' => 'Part Number', 'key' => 'excel_part_number'],
                ['label' => 'Status', 'key' => 'status'],
                ['label' => 'Quantity', 'key' => 'quantity', 'align' => 'center'],
            ];
        }

        return [
            'title' => "{$kpiDisplayName} — Detailed KPI Breakdown",
            'section_name' => substr("KPI_{$kpiKey}", 0, 30),
            'date_range' => now()->format('d-M-Y'),
            'active_filters' => $activeFiltersStr,
            'generated_at' => now()->format('d-M-Y H:i:s T'),
            'generated_by' => $request->user()?->name ?? 'FAITH AUTOMATION User',
            'filename' => $filename,
            'columns' => $columns,
            'rows' => $drilldown['all_data'] ?? $drilldown['data'],
        ];
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
        $sheet->mergeCells('A1:I1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('0F172A'));
        $sheet->getRowDimension(1)->setRowHeight(24);

        // 2. Report Subtitle
        $sheet->setCellValue('A2', $data['title']);
        $sheet->mergeCells('A2:I2');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('2563EB'));

        // 3. Metadata Box
        $sheet->setCellValue('A3', 'Date: ' . $data['date_range'] . '   |   Filters: ' . $data['active_filters'] . '   |   Generated: ' . $data['generated_at'] . '   |   By: ' . $data['generated_by']);
        $sheet->mergeCells('A3:I3');
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));
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
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F172A');
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($startRow)->setRowHeight(22);

        // 5. Data Rows
        $currentRow = $startRow + 1;
        foreach ($data['rows'] as $row) {
            $colIndex = 'A';
            foreach ($data['columns'] as $col) {
                $val = $row[$col['key']] ?? '';
                $textKeys = ['part_no', 'part_number', 'excel_part_number', 'unit_no', 'jig_no', 'side', 'source_side', 'ecn_number', 'project_code'];
                if (in_array($col['key'], $textKeys)) {
                    $sheet->setCellValueExplicit($colIndex . $currentRow, (string)$val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($colIndex . $currentRow, $val);
                }
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
     * Export project-level Jig material status matching authoritative reference format.
     * Single worksheet only, one row per Jig, correct numeric TOTAL row.
     * Blank cells if date or supplier data is not in the database/website.
     *
     * @param Project $project
     * @param array $filters
     * @return StreamedResponse
     */
    public function exportProjectJigs(Project $project, array $filters = []): StreamedResponse
    {
        $hierarchy = $this->hierarchyService->getDepartmentHierarchy('manager', $project->id, $filters);
        $jigs = $hierarchy['jigs'] ?? [];

        // Preload suppliers by Jig and (Jig, Side) - Zero N+1 queries
        $assignedSuppliers = SupplierAssignment::query()
            ->where('project_id', $project->id)
            ->where('status', 'active')
            ->with('supplier')
            ->get()
            ->groupBy(fn($a) => strtoupper(trim((string)$a->jig_no)));

        $bomSuppliers = BomItem::query()
            ->join('bom_requirements', 'bom_requirements.bom_item_id', '=', 'bom_items.id')
            ->where('bom_items.project_id', $project->id)
            ->whereNotNull('bom_items.jig_no')
            ->with('supplier')
            ->get(['bom_items.id', 'bom_items.jig_no', 'bom_requirements.side', 'bom_items.supplier_id', 'bom_items.supplier_name_raw']);

        $bomSuppliersByJigSide = $bomSuppliers->groupBy(function ($i) {
            $j = strtoupper(trim((string)$i->jig_no));
            $s = strtoupper(trim((string)$i->side));
            return "{$j}|{$s}";
        });

        $bomSuppliersByJig = $bomSuppliers->groupBy(fn($i) => strtoupper(trim((string)$i->jig_no)));

        // Preload latest receipt dates by (Jig, Side) and Jig
        $receiptDatesByJigSide = ReceiptItem::query()
            ->join('bom_items', 'bom_items.id', '=', 'receipt_items.bom_item_id')
            ->leftJoin('receipts', 'receipts.id', '=', 'receipt_items.receipt_id')
            ->where('bom_items.project_id', $project->id)
            ->whereIn('receipt_items.status', QuantityCalculationService::VALID_RECEIPT_STATUSES)
            ->select(
                'bom_items.jig_no',
                'receipt_items.side',
                DB::raw('MAX(COALESCE(receipts.receipt_date, receipt_items.created_at)) as max_rec_date')
            )
            ->groupBy('bom_items.jig_no', 'receipt_items.side')
            ->get()
            ->mapWithKeys(function ($r) {
                $j = strtoupper(trim((string)$r->jig_no));
                $s = strtoupper(trim((string)$r->side));
                return ["{$j}|{$s}" => $r->max_rec_date];
            });

        $receiptDatesByJig = ReceiptItem::query()
            ->join('bom_items', 'bom_items.id', '=', 'receipt_items.bom_item_id')
            ->leftJoin('receipts', 'receipts.id', '=', 'receipt_items.receipt_id')
            ->where('bom_items.project_id', $project->id)
            ->whereIn('receipt_items.status', QuantityCalculationService::VALID_RECEIPT_STATUSES)
            ->select('bom_items.jig_no', DB::raw('MAX(COALESCE(receipts.receipt_date, receipt_items.created_at)) as max_rec_date'))
            ->groupBy('bom_items.jig_no')
            ->pluck('max_rec_date', 'bom_items.jig_no')
            ->mapWithKeys(fn($date, $jig) => [strtoupper(trim((string)$jig)) => $date]);

        // Preload earliest release/assignment dates by Jig
        $assignmentDatesByJig = SupplierAssignment::query()
            ->where('project_id', $project->id)
            ->where('status', 'active')
            ->whereNotNull('assignment_date')
            ->select('jig_no', DB::raw('MIN(assignment_date) as min_assign_date'))
            ->groupBy('jig_no')
            ->pluck('min_assign_date', 'jig_no')
            ->mapWithKeys(fn($date, $jig) => [strtoupper(trim((string)$jig)) => $date]);

        $projRequired = (int)($hierarchy['canonical_summary']['total_required'] ?? array_sum(array_column($jigs, 'total_required')));
        $projAsmComp  = (int)($hierarchy['canonical_summary']['assembly_completed'] ?? array_sum(array_map(fn($j) => $j['metrics']['assembly_completed'] ?? 0, $jigs)));
        $projCompletionRatio = $projRequired > 0 ? min(1.0, round($projAsmComp / $projRequired, 4)) : 0.0;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $safeSheetTitle = substr(preg_replace('/[\\\\\\/?*\\[\\]]/', '', $project->project_code . ' Jigs'), 0, 31);
        $sheet->setTitle($safeSheetTitle);

        $headers = [
            'Fix No.',
            'Design Release date',
            'Supplier Name',
            'Mfg Receipt Date',
            'Total',
            'Received',
            'Pending',
            'Quality',
            'Rework',
            'Paintshop',
            'Assembly',
            'ECN',
            'Project Completion %'
        ];

        $currentRow = 1;

        foreach ($jigs as $jig) {
            $jigName = $jig['jig_name'] ?? 'N/A';
            $jKey = strtoupper(trim((string)$jigName));

            // 1. Jig Name Header Row (Merged A to M, 14pt bold centered)
            $bannerRow = $currentRow;
            $sheet->setCellValue('A' . $bannerRow, $jigName);
            $sheet->mergeCells("A{$bannerRow}:M{$bannerRow}");
            $sheet->getStyle("A{$bannerRow}")->getFont()->setBold(true)->setSize(14)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('000000'));
            $sheet->getStyle("A{$bannerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A{$bannerRow}:M{$bannerRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF000000');
            $sheet->getRowDimension($bannerRow)->setRowHeight(28);
            $currentRow++;

            // 2. Table Header Row (Gold/Tan fill #F5E6CB, 10pt bold centered)
            $headerRow = $currentRow;
            $colChar = 'A';
            foreach ($headers as $h) {
                $sheet->setCellValue($colChar . $headerRow, $h);
                $colChar++;
            }
            $sheet->getStyle("A{$headerRow}:M{$headerRow}")->getFont()->setBold(true)->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('000000'));
            $sheet->getStyle("A{$headerRow}:M{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF5E6CB');
            $sheet->getStyle("A{$headerRow}:M{$headerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            $sheet->getStyle("A{$headerRow}:M{$headerRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF000000');
            $sheet->getRowDimension($headerRow)->setRowHeight(26);
            $currentRow++;

            // 3. Side / Fixture Rows
            $sidesMap = [];
            foreach ($jig['units'] ?? [] as $u) {
                if (!empty($u['sides'])) {
                    foreach ($u['sides'] as $sKey => $sData) {
                        $normSide = strtoupper(trim((string)$sKey));
                        if (!isset($sidesMap[$normSide])) {
                            $sidesMap[$normSide] = [
                                'total_required' => 0,
                                'total_received' => 0,
                                'total_pending' => 0,
                                'quality' => 0,
                                'rework' => 0,
                                'paintshop' => 0,
                                'assembly' => 0,
                                'ecn' => 0,
                            ];
                        }
                        $sidesMap[$normSide]['total_required'] += (int)($sData['total_required'] ?? 0);
                        $sidesMap[$normSide]['total_received'] += (int)($sData['total_received'] ?? 0);
                        $sidesMap[$normSide]['total_pending'] += (int)($sData['pending_quantity'] ?? 0);
                        $m = $sData['metrics'] ?? [];
                        $sidesMap[$normSide]['quality'] += (int)($m['parts_in_qc'] ?? (($m['qc_pending_arrival'] ?? 0) + ($m['qc_pending_inspection'] ?? 0)));
                        $sidesMap[$normSide]['rework'] += (int)($m['parts_in_rework'] ?? ($m['rework_pending'] ?? 0));
                        $sidesMap[$normSide]['paintshop'] += (int)($m['parts_in_paint'] ?? ($m['paint_ready'] ?? 0));
                        $sidesMap[$normSide]['assembly'] += (int)($sData['assembly_completed'] ?? ($m['assembly_completed'] ?? 0));
                        $sidesMap[$normSide]['ecn'] += (int)($sData['ecn_count'] ?? 0);
                    }
                } else {
                    $normSide = 'COMMON';
                    if (!isset($sidesMap[$normSide])) {
                        $sidesMap[$normSide] = [
                            'total_required' => 0,
                            'total_received' => 0,
                            'total_pending' => 0,
                            'quality' => 0,
                            'rework' => 0,
                            'paintshop' => 0,
                            'assembly' => 0,
                            'ecn' => 0,
                        ];
                    }
                    $sidesMap[$normSide]['total_required'] += (int)($u['total_required'] ?? 0);
                    $sidesMap[$normSide]['total_received'] += (int)($u['total_received'] ?? 0);
                    $sidesMap[$normSide]['total_pending'] += (int)($u['total_pending'] ?? 0);
                    $m = $u['metrics'] ?? [];
                    $sidesMap[$normSide]['quality'] += (int)($m['parts_in_qc'] ?? (($m['qc_pending_arrival'] ?? 0) + ($m['qc_pending_inspection'] ?? 0)));
                    $sidesMap[$normSide]['rework'] += (int)($m['parts_in_rework'] ?? ($m['rework_pending'] ?? 0));
                    $sidesMap[$normSide]['paintshop'] += (int)($m['parts_in_paint'] ?? ($m['paint_ready'] ?? 0));
                    $sidesMap[$normSide]['assembly'] += (int)($m['assembly_completed'] ?? 0);
                    $sidesMap[$normSide]['ecn'] += (int)($u['ecn_count'] ?? 0);
                }
            }

            // Filter out sides with zero activity if other active sides exist
            if (count($sidesMap) > 1) {
                $filteredSides = array_filter($sidesMap, fn($v) => ($v['total_required'] > 0 || $v['total_received'] > 0 || $v['ecn'] > 0));
                if (!empty($filteredSides)) {
                    $sidesMap = $filteredSides;
                }
            }

            // Sort sides: RH first, then LH, then COMMON
            uksort($sidesMap, function ($a, $b) {
                if ($a === 'RH' && $b === 'LH') return -1;
                if ($a === 'LH' && $b === 'RH') return 1;
                return strcmp($a, $b);
            });

            $jigTotals = [
                'total' => 0,
                'received' => 0,
                'pending' => 0,
                'quality' => 0,
                'rework' => 0,
                'paintshop' => 0,
                'assembly' => 0,
                'ecn' => 0,
            ];

            foreach ($sidesMap as $sKey => $vals) {
                $fixNo = ($sKey === 'COMMON' || empty($sKey)) ? $jigName : "{$jigName}-{$sKey}";
                $jSideKey = "{$jKey}|{$sKey}";

                // Supplier Name: check (jig, side), then jig; leave blank if none in database/website
                $suppList = collect();
                if ($bomSuppliersByJigSide->has($jSideKey)) {
                    $suppList = $suppList->merge($bomSuppliersByJigSide->get($jSideKey)->pluck('supplier.name'));
                    $suppList = $suppList->merge($bomSuppliersByJigSide->get($jSideKey)->pluck('supplier_name_raw'));
                }
                if ($suppList->isEmpty()) {
                    if ($assignedSuppliers->has($jKey)) {
                        $suppList = $suppList->merge($assignedSuppliers->get($jKey)->pluck('supplier.name'));
                    }
                    if ($bomSuppliersByJig->has($jKey)) {
                        $suppList = $suppList->merge($bomSuppliersByJig->get($jKey)->pluck('supplier.name'));
                        $suppList = $suppList->merge($bomSuppliersByJig->get($jKey)->pluck('supplier_name_raw'));
                    }
                }
                $supplierName = $suppList->filter(fn($n) => !empty($n) && strtolower($n) !== 'standard')->unique()->values()->implode(', ');

                // Design Release Date: from assignment_date for this jig; leave blank if none in database/website
                $designDateRaw = $assignmentDatesByJig->get($jKey);
                $designDate = '';
                if ($designDateRaw) {
                    try {
                        $designDate = Carbon::parse($designDateRaw)->format('d-M');
                    } catch (\Exception $e) {
                        $designDate = '';
                    }
                }

                // Mfg Receipt Date: check (jig, side), then jig; leave blank if none in database/website
                $mfgDateRaw = $receiptDatesByJigSide->get($jSideKey) ?? $receiptDatesByJig->get($jKey);
                $mfgDate = '';
                if ($mfgDateRaw) {
                    try {
                        $mfgDate = Carbon::parse($mfgDateRaw)->format('d-M');
                    } catch (\Exception $e) {
                        $mfgDate = '';
                    }
                }

                $total = (int) $vals['total_required'];
                $received = (int) $vals['total_received'];
                $pending = (int) $vals['total_pending'];
                $quality = (int) $vals['quality'];
                $rework = (int) $vals['rework'];
                $paintshop = (int) $vals['paintshop'];
                $assembly = (int) $vals['assembly'];
                $ecn = (int) $vals['ecn'];

                $jigTotals['total'] += $total;
                $jigTotals['received'] += $received;
                $jigTotals['pending'] += $pending;
                $jigTotals['quality'] += $quality;
                $jigTotals['rework'] += $rework;
                $jigTotals['paintshop'] += $paintshop;
                $jigTotals['assembly'] += $assembly;
                $jigTotals['ecn'] += $ecn;

                // Set row values
                $sheet->setCellValueExplicit('A' . $currentRow, $fixNo, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B' . $currentRow, $designDate, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('C' . $currentRow, $supplierName, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('D' . $currentRow, $mfgDate, DataType::TYPE_STRING);

                // Numeric values
                $sheet->setCellValueExplicit('E' . $currentRow, $total, DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit('F' . $currentRow, $received, DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit('G' . $currentRow, $pending, DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit('H' . $currentRow, $quality, DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit('I' . $currentRow, $rework, DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit('J' . $currentRow, $paintshop, DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit('K' . $currentRow, $assembly, DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit('L' . $currentRow, $ecn, DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit('M' . $currentRow, $projCompletionRatio, DataType::TYPE_NUMERIC);
                $sheet->getStyle('M' . $currentRow)->getNumberFormat()->setFormatCode('0.0%');

                // Row formatting & borders
                $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle('B' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle('C' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle('D' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle("E{$currentRow}:M{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle("A{$currentRow}:M{$currentRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF000000');
                $sheet->getRowDimension($currentRow)->setRowHeight(20);
                $currentRow++;
            }

            // 4. Jig TOTAL row
            $totalRow = $currentRow;
            $sheet->setCellValue('A' . $totalRow, 'TOTAL');
            $sheet->setCellValue('B' . $totalRow, '');
            $sheet->setCellValue('C' . $totalRow, '');
            $sheet->setCellValue('D' . $totalRow, '');

            $sheet->setCellValueExplicit('E' . $totalRow, $jigTotals['total'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit('F' . $totalRow, $jigTotals['received'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit('G' . $totalRow, $jigTotals['pending'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit('H' . $totalRow, $jigTotals['quality'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit('I' . $totalRow, $jigTotals['rework'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit('J' . $totalRow, $jigTotals['paintshop'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit('K' . $totalRow, $jigTotals['assembly'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit('L' . $totalRow, $jigTotals['ecn'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit('M' . $totalRow, $projCompletionRatio, DataType::TYPE_NUMERIC);
            $sheet->getStyle('M' . $totalRow)->getNumberFormat()->setFormatCode('0.0%');

            $totalRange = "A{$totalRow}:M{$totalRow}";
            $sheet->getStyle($totalRange)->getFont()->setBold(true)->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('000000'));
            $sheet->getStyle('A' . $totalRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("E{$totalRow}:M{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle($totalRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF000000');
            $sheet->getRowDimension($totalRow)->setRowHeight(22);
            $currentRow++;

            // 5. Blank spacer row between Jigs
            $sheet->getRowDimension($currentRow)->setRowHeight(18);
            $currentRow++;
        }

        // Column widths for immediate readability
        $sheet->getColumnDimension('A')->setWidth(18); // Fix No.
        $sheet->getColumnDimension('B')->setWidth(20); // Design Release Date
        $sheet->getColumnDimension('C')->setWidth(26); // Supplier Name
        $sheet->getColumnDimension('D')->setWidth(18); // Mfg Receipt Date
        $sheet->getColumnDimension('E')->setWidth(12); // Total
        $sheet->getColumnDimension('F')->setWidth(12); // Received
        $sheet->getColumnDimension('G')->setWidth(12); // Pending
        $sheet->getColumnDimension('H')->setWidth(12); // Quality
        $sheet->getColumnDimension('I')->setWidth(12); // Rework
        $sheet->getColumnDimension('J')->setWidth(12); // Paintshop
        $sheet->getColumnDimension('K')->setWidth(12); // Assembly
        $sheet->getColumnDimension('L')->setWidth(12); // ECN
        $sheet->getColumnDimension('M')->setWidth(22); // Project Completion %

        $filename = "{$project->project_code}-Jig-Material-Status.xlsx";
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
