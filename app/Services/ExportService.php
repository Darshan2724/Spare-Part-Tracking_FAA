<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportService
{
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

        return [
            'title' => "{$kpiDisplayName} — Detailed KPI Breakdown",
            'section_name' => substr("KPI_{$kpiKey}", 0, 30),
            'date_range' => now()->format('d-M-Y'),
            'active_filters' => $activeFiltersStr,
            'generated_at' => now()->format('d-M-Y H:i:s T'),
            'generated_by' => $request->user()?->name ?? 'FAITH AUTOMATION User',
            'filename' => $filename,
            'columns' => $drilldown['columns'],
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
        $sheet->mergeCells('A1:H1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('0F172A'));
        $sheet->getRowDimension(1)->setRowHeight(24);

        // 2. Report Subtitle
        $sheet->setCellValue('A2', $data['title']);
        $sheet->mergeCells('A2:H2');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('2563EB'));

        // 3. Metadata Box
        $sheet->setCellValue('A3', 'Date: ' . $data['date_range'] . '   |   Filters: ' . $data['active_filters'] . '   |   Generated: ' . $data['generated_at'] . '   |   By: ' . $data['generated_by']);
        $sheet->mergeCells('A3:H3');
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
}
