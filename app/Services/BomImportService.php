<?php

namespace App\Services;

use App\Models\BomImportBatch;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BomImportService
{
    /**
     * Check if the uploaded file has already been successfully imported.
     */
    public function checkDuplicateFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $fileHash = hash_file('sha256', $path);
        $existingBatch = BomImportBatch::where('file_hash', $fileHash)
            ->where('status', 'completed')
            ->with(['importer', 'project'])
            ->orderByDesc('created_at')
            ->first();

        if ($existingBatch) {
            $importDate = $existingBatch->created_at ? $existingBatch->created_at->format('d M Y, h:i A') : 'Previously';
            $importerName = $existingBatch->importer?->name ?? 'System Administrator';
            $origName = $existingBatch->original_filename ?? $existingBatch->filename;
            $projCode = $existingBatch->project?->project_code ?? 'Project';

            return [
                'is_duplicate' => true,
                'file_hash' => $fileHash,
                'batch_id' => $existingBatch->id,
                'original_filename' => $origName,
                'imported_at' => $importDate,
                'imported_by' => $importerName,
                'project_code' => $projCode,
                'message' => "This exact BOM file has already been imported on {$importDate} by {$importerName} (Original: '{$origName}'). The same file cannot be imported again.",
            ];
        }

        return null;
    }

    /**
     * Preview BOM from file path using the FA-279 New MFG BOM Standard.
     */
    public function previewFromPath(string $path, ?string $filename = null): array
    {
        $filename = $filename ?? basename($path);

        // 1. Immediate duplicate check before parsing
        $duplicateInfo = $this->checkDuplicateFile($path);
        if ($duplicateInfo) {
            return [
                'success' => false,
                'is_duplicate' => true,
                'error_title' => 'BOM Already Imported',
                'message' => $duplicateInfo['message'],
                'duplicate_details' => $duplicateInfo,
                'filename' => $filename,
                'sheet' => 'N/A',
                'summary' => $this->emptySummary(),
                'rows' => [],
                'errors' => [$duplicateInfo['message']],
                'warnings' => [],
            ];
        }

        $extracted = $this->extractAndValidateRows($path, $filename);

        return [
            'success' => empty($extracted['errors']),
            'filename' => $filename,
            'sheet' => $extracted['sheet_name'],
            'summary' => $extracted['summary'],
            'rows' => $extracted['rows'],
            'errors' => $extracted['errors'],
            'warnings' => $extracted['warnings'],
        ];
    }

    /**
     * Import BOM into PostgreSQL database using the FA-279 New MFG BOM Standard.
     */
    public function importFromPath(string $path, array $data, int $userId): array
    {
        $filename = $data['filename'] ?? basename($path);

        // 1. Strict duplicate check before transaction
        $duplicateInfo = $this->checkDuplicateFile($path);
        if ($duplicateInfo) {
            return [
                'success' => false,
                'is_duplicate' => true,
                'error_title' => 'BOM Already Imported',
                'message' => $duplicateInfo['message'],
                'duplicate_details' => $duplicateInfo,
                'errors' => [$duplicateInfo['message']],
                'warnings' => [],
            ];
        }

        $extracted = $this->extractAndValidateRows($path, $filename);

        if (!empty($extracted['errors'])) {
            return [
                'success' => false,
                'message' => 'BOM contains validation errors and cannot be imported.',
                'errors' => $extracted['errors'],
                'warnings' => $extracted['warnings'],
            ];
        }

        $rows = $extracted['rows'];
        if (empty($rows)) {
            return [
                'success' => false,
                'message' => 'No valid BOM data rows found in the file.',
                'errors' => ['The uploaded BOM file contains no data rows.'],
            ];
        }

        $fileHash = hash_file('sha256', $path);
        $fileSize = is_file($path) ? filesize($path) : null;

        try {
            return DB::transaction(function () use ($rows, $filename, $userId, $extracted, $fileHash, $fileSize) {
                $createdProjects = [];
                $totalRequirementsCreated = 0;
                $totalQuantityImported = 0;

                // Group rows by project code
                $projectGroups = collect($rows)->groupBy('project_code');
                $projectCodesList = $projectGroups->keys()->values()->toArray();

                foreach ($projectGroups as $projectCode => $projectRows) {
                    $project = Project::firstOrCreate(
                        ['project_code' => $projectCode],
                        [
                            'name' => $projectCode,
                            'status' => 'active',
                            'created_by' => $userId,
                        ]
                    );
                    $createdProjects[$project->id] = $project;

                    $batch = BomImportBatch::create([
                        'project_id' => $project->id,
                        'filename' => $filename,
                        'file_hash' => $fileHash,
                        'file_size_bytes' => $fileSize,
                        'original_filename' => $filename,
                        'project_codes' => $projectCodesList,
                        'imported_by' => $userId,
                        'total_rows' => $projectRows->count(),
                        'successful_rows' => $projectRows->count(),
                        'status' => 'completed',
                    ]);

                    foreach ($projectRows as $row) {
                        $bomItem = BomItem::firstOrCreate(
                            [
                                'project_id' => $project->id,
                                'jig_no' => $row['jig_no'],
                                'unit_no' => $row['unit_no'],
                                'standard_part_no' => $row['part_no'],
                            ],
                            [
                                'import_batch_id' => $batch->id,
                                'proj_spec_yn' => 'Y',
                            ]
                        );

                        BomRequirement::updateOrCreate(
                            [
                                'bom_item_id' => $bomItem->id,
                                'side' => $row['side'],
                            ],
                            [
                                'required_quantity' => (int) $row['qty'],
                            ]
                        );

                        $totalRequirementsCreated++;
                        $totalQuantityImported += (int) $row['qty'];
                    }
                }

                SystemLogService::log([
                    'severity' => 'INFO',
                    'category' => 'system_health_logs',
                    'module' => 'STORE',
                    'message' => "BOM imported successfully: {$filename} (Hash: {$fileHash})",
                    'details' => [
                        'filename' => $filename,
                        'file_hash' => $fileHash,
                        'projects' => array_values(array_map(fn ($p) => $p->project_code, $createdProjects)),
                        'total_requirements' => $totalRequirementsCreated,
                        'total_pieces' => $totalQuantityImported,
                        'user_id' => $userId,
                    ],
                ]);

                return [
                    'success' => true,
                    'message' => "BOM imported successfully. {$totalRequirementsCreated} requirements loaded ({$totalQuantityImported} total pieces).",
                    'filename' => $filename,
                    'file_hash' => $fileHash,
                    'summary' => $extracted['summary'],
                    'imported_rows' => count($rows),
                ];
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'bom_import_batches_file_hash_unique') || str_contains($e->getMessage(), 'duplicate key')) {
                return [
                    'success' => false,
                    'is_duplicate' => true,
                    'error_title' => 'BOM Already Imported',
                    'message' => "This exact BOM file has already been imported by another user/process. Duplicate import was blocked.",
                    'errors' => ["This exact BOM file has already been imported. Duplicate import was blocked."],
                ];
            }
            throw $e;
        }
    }

    /**
     * Extract and strictly validate rows according to FA-279 Standard.
     */
    protected function extractAndValidateRows(string $path, string $filename): array
    {
        $errors = [];
        $warnings = [];

        try {
            $reader = IOFactory::createReaderForFile($path);
            $spreadsheet = $reader->load($path);
        } catch (\Throwable $e) {
            return [
                'sheet_name' => 'N/A',
                'summary' => $this->emptySummary(),
                'rows' => [],
                'errors' => ['Failed to read Excel file: ' . $e->getMessage()],
                'warnings' => [],
            ];
        }

        $sheet = $spreadsheet->getActiveSheet();
        $sheetName = $sheet->getTitle();
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        // 1. Scan for Header Row and Check for Legacy Formats
        $headerRowIndex = null;
        $headerMap = [];
        $legacyDetected = false;

        for ($r = 1; $r <= min(15, $highestRow); $r++) {
            $rowValues = [];
            foreach (range('A', $highestColumn) as $col) {
                $val = trim((string) $sheet->getCell($col . $r)->getValue());
                if ($val !== '') {
                    $rowValues[$col] = $val;
                }
            }

            // Check if this row contains legacy columns
            $upperJoined = strtoupper(implode(' ', array_values($rowValues)));
            if (str_contains($upperJoined, 'QTYRH') || str_contains($upperJoined, 'QTYLH') || str_contains($upperJoined, 'PROJSPECYN')) {
                $legacyDetected = true;
            }

            // Check for FA-279 column matches
            $mappedCols = $this->matchFa279Headers($rowValues);
            if ($mappedCols !== null) {
                $headerRowIndex = $r;
                $headerMap = $mappedCols;
                break;
            }
        }

        // 2. Reject Legacy Format
        if ($legacyDetected && $headerRowIndex === null) {
            return [
                'sheet_name' => $sheetName,
                'summary' => $this->emptySummary(),
                'rows' => [],
                'errors' => [
                    'Invalid BOM format. SpareTrack now accepts only the FA-279 New MFG BOM format: Project Code, Jig No, Unit No, Part No, Side and Qty.',
                ],
                'warnings' => ['Legacy BOM format with QTYRH/QTYLH/Parent/StandardPartNo has been retired and is no longer supported.'],
            ];
        }

        if ($headerRowIndex === null) {
            return [
                'sheet_name' => $sheetName,
                'summary' => $this->emptySummary(),
                'rows' => [],
                'errors' => [
                    'Invalid BOM format. Could not locate required FA-279 column headers (Project Code, Jig No, Unit No, Part No, Side, Qty).',
                ],
                'warnings' => ['Please ensure headers are in the approved format (e.g. Project Code, Jig, Unit No., Part No., Side, Qty).'],
            ];
        }

        // 3. Extract and Validate Data Rows
        $validRows = [];
        $seenCombinations = [];
        $uniqueProjects = [];
        $uniqueJigs = [];
        $uniqueUnits = [];
        $uniqueParts = [];
        $sideStats = [
            'RH' => ['count' => 0, 'qty' => 0],
            'LH' => ['count' => 0, 'qty' => 0],
            'COMMON' => ['count' => 0, 'qty' => 0],
        ];
        $totalRequiredQuantity = 0;

        for ($r = $headerRowIndex + 1; $r <= $highestRow; $r++) {
            $projectCode = trim((string) $sheet->getCell($headerMap['project_code'] . $r)->getValue());
            $jigNo = trim((string) $sheet->getCell($headerMap['jig_no'] . $r)->getValue());
            $unitNo = trim((string) $sheet->getCell($headerMap['unit_no'] . $r)->getValue());
            $partNo = trim((string) $sheet->getCell($headerMap['part_no'] . $r)->getValue());
            $sideRaw = trim((string) $sheet->getCell($headerMap['side'] . $r)->getValue());
            $qtyRaw = $sheet->getCell($headerMap['qty'] . $r)->getValue();

            // Skip completely empty rows
            if ($projectCode === '' && $jigNo === '' && $unitNo === '' && $partNo === '' && $sideRaw === '' && $qtyRaw === null) {
                continue;
            }

            $rowErrors = [];

            if ($projectCode === '') {
                $rowErrors[] = "Row {$r}: Project Code cannot be blank.";
            }
            if ($jigNo === '') {
                $rowErrors[] = "Row {$r}: Jig No cannot be blank.";
            }
            if ($unitNo === '') {
                $rowErrors[] = "Row {$r}: Unit No cannot be blank.";
            }
            if ($partNo === '') {
                $rowErrors[] = "Row {$r}: Part No cannot be blank.";
            }

            // Qty validation
            $qty = $this->parseQuantity($qtyRaw);
            if ($qty === null || $qty <= 0) {
                $rowErrors[] = "Row {$r}: Qty must be a positive integer (found '{$qtyRaw}').";
            }

            // Side normalization & validation (Supports separated LH/RH for multi-side rows)
            $parsedSides = $qty ? $this->parseSidesAndQuantities($sideRaw, $qty) : null;
            if ($parsedSides === null) {
                $rowErrors[] = "Row {$r}: Invalid Side '{$sideRaw}'. Must be RH (or R), LH (or L), or COMMON.";
            }

            // Duplicate detection across split sides
            if ($parsedSides !== null) {
                foreach ($parsedSides as $ps) {
                    $s = $ps['side'];
                    $comboKey = "{$projectCode}|{$jigNo}|{$unitNo}|{$partNo}|{$s}";
                    if (isset($seenCombinations[$comboKey])) {
                        $prevRow = $seenCombinations[$comboKey];
                        $rowErrors[] = "Row {$r}: Duplicate combination for Project '{$projectCode}', Jig '{$jigNo}', Unit '{$unitNo}', Part '{$partNo}', Side '{$s}' (first seen on Row {$prevRow}).";
                    }
                }
            }

            if (!empty($rowErrors)) {
                $errors = array_merge($errors, $rowErrors);
            } else {
                foreach ($parsedSides as $ps) {
                    $s = $ps['side'];
                    $sideQty = $ps['qty'];
                    $comboKey = "{$projectCode}|{$jigNo}|{$unitNo}|{$partNo}|{$s}";
                    $seenCombinations[$comboKey] = $r;

                    $validRows[] = [
                        'row_number' => $r,
                        'project_code' => $projectCode,
                        'jig_no' => $jigNo,
                        'unit_no' => $unitNo,
                        'part_no' => $partNo,
                        'side' => $s,
                        'qty' => $sideQty,
                    ];

                    $uniqueProjects[$projectCode] = true;
                    $uniqueJigs[$jigNo] = true;
                    $uniqueUnits[$unitNo] = true;
                    $uniqueParts[$partNo] = true;
                    $sideStats[$s]['count']++;
                    $sideStats[$s]['qty'] += $sideQty;
                    $totalRequiredQuantity += $sideQty;
                }
            }
        }

        $summary = [
            'total_rows' => count($validRows),
            'total_projects' => count($uniqueProjects),
            'total_jigs' => count($uniqueJigs),
            'total_units' => count($uniqueUnits),
            'unique_parts' => count($uniqueParts),
            'side_distribution' => $sideStats,
            'total_required_quantity' => $totalRequiredQuantity,
            'header_row' => $headerRowIndex,
        ];

        return [
            'sheet_name' => $sheetName,
            'summary' => $summary,
            'rows' => $validRows,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Match FA-279 required headers from row cells.
     */
    protected function matchFa279Headers(array $rowCells): ?array
    {
        $map = [];

        foreach ($rowCells as $col => $header) {
            $clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', trim((string) $header)));

            if (in_array($clean, ['projectcode', 'project', 'projcode'], true)) {
                $map['project_code'] = $col;
            } elseif (in_array($clean, ['jig', 'jigno', 'jignumber', 'assemblyjig'], true)) {
                $map['jig_no'] = $col;
            } elseif (in_array($clean, ['unitno', 'unit', 'unitnumber'], true)) {
                $map['unit_no'] = $col;
            } elseif (in_array($clean, ['partno', 'part', 'partnumber', 'standardpartno'], true)) {
                $map['part_no'] = $col;
            } elseif (in_array($clean, ['side'], true)) {
                $map['side'] = $col;
            } elseif (in_array($clean, ['qty', 'quantity', 'requiredqty'], true)) {
                $map['qty'] = $col;
            }
        }

        $required = ['project_code', 'jig_no', 'unit_no', 'part_no', 'side', 'qty'];
        foreach ($required as $field) {
            if (!isset($map[$field])) {
                return null;
            }
        }

        return $map;
    }

    /**
     * Normalize Side string to RH, LH, or COMMON.
     */
    protected function normalizeSide(string $value): ?string
    {
        $upper = strtoupper(trim($value));

        if (in_array($upper, ['R', 'RH', 'RIGHT'], true)) {
            return 'RH';
        }
        if (in_array($upper, ['L', 'LH', 'LEFT'], true)) {
            return 'LH';
        }
        if (in_array($upper, ['C', 'COM', 'COMMON', 'BOTH'], true)) {
            return 'COMMON';
        }

        return null;
    }

    /**
     * Parse and separate sides and allocate exact quantities without doubling.
     */
    protected function parseSidesAndQuantities(string $sideRaw, int $totalQty): ?array
    {
        $clean = strtoupper(trim($sideRaw));

        // Direct single sides
        if (in_array($clean, ['R', 'RH', 'RIGHT'], true)) {
            return [['side' => 'RH', 'qty' => $totalQty]];
        }
        if (in_array($clean, ['L', 'LH', 'LEFT'], true)) {
            return [['side' => 'LH', 'qty' => $totalQty]];
        }
        if (in_array($clean, ['C', 'COM', 'COMMON'], true)) {
            return [['side' => 'COMMON', 'qty' => $totalQty]];
        }

        // Multi-side representations: "LH, RH", "LH,RH", "L, R", "L/R", "LH / RH", "RH, LH", "BOTH"
        if (in_array($clean, ['BOTH', 'L, R', 'L,R', 'L/R', 'LH, RH', 'LH,RH', 'LH/RH', 'RH, LH', 'RH,LH', 'RH/LH', 'LH & RH', 'RH & LH', 'L & R'], true)
            || (str_contains($clean, 'LH') && str_contains($clean, 'RH'))
            || (str_contains($clean, 'L') && str_contains($clean, 'R') && strlen($clean) <= 6)
        ) {
            // Allocate total quantity without doubling: Original Quantity = Total Represented Quantity
            if ($totalQty >= 2) {
                $half = (int) floor($totalQty / 2);
                $rem = $totalQty - ($half * 2);
                return [
                    ['side' => 'LH', 'qty' => $half + $rem],
                    ['side' => 'RH', 'qty' => $half],
                ];
            } else {
                return [
                    ['side' => 'COMMON', 'qty' => $totalQty],
                ];
            }
        }

        return null;
    }

    /**
     * Parse positive integer quantity.
     */
    protected function parseQuantity(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $clean = trim((string) $value);
        if (!is_numeric($clean)) {
            return null;
        }

        $intVal = (int) $clean;
        return $intVal > 0 ? $intVal : null;
    }

    protected function emptySummary(): array
    {
        return [
            'total_rows' => 0,
            'total_projects' => 0,
            'total_jigs' => 0,
            'total_units' => 0,
            'unique_parts' => 0,
            'side_distribution' => [
                'RH' => ['count' => 0, 'qty' => 0],
                'LH' => ['count' => 0, 'qty' => 0],
                'COMMON' => ['count' => 0, 'qty' => 0],
            ],
            'total_required_quantity' => 0,
            'header_row' => 0,
        ];
    }
}
