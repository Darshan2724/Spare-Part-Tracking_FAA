<?php

namespace App\Services;

use App\Models\EcnImportBatch;
use App\Models\EcnRequirement;
use App\Models\Project;
use App\Services\ProjectIdentityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class EcnImportService
{
    public const VALID_ECN_SIDES = ['LA', 'RA', 'AL', 'AR', 'L', 'R'];

    public function __construct(
        protected ProjectIdentityResolver $projectResolver = new ProjectIdentityResolver()
    ) {}

    /**
     * Map original ECN side to standard UI display label (LH/RH).
     */
    public static function mapSideDisplay(string $side): string
    {
        $upper = strtoupper(trim($side));
        return in_array($upper, ['LA', 'AL', 'L']) ? 'LH' : 'RH';
    }

    /**
     * Map original ECN side to normalization family (LEFT/RIGHT).
     */
    public static function mapSideFamily(string $side): string
    {
        $upper = strtoupper(trim($side));
        return in_array($upper, ['LA', 'AL', 'L']) ? 'LEFT' : 'RIGHT';
    }

    /**
     * Compute SHA-256 hash of file.
     */
    public function computeFileHash(string $path): string
    {
        return hash_file('sha256', $path);
    }

    /**
     * Check if exact file or identical content was previously imported.
     */
    public function checkDuplicateFile(string $path, string $filename): ?array
    {
        $hash = $this->computeFileHash($path);

        $hashMatch = EcnImportBatch::where('file_hash', $hash)
            ->where('status', 'completed')
            ->first();

        if ($hashMatch) {
            return [
                'is_duplicate' => true,
                'is_duplicate_filename' => false,
                'duplicate_type' => 'content_hash',
                'matched_batch_id' => $hashMatch->id,
                'original_filename' => $hashMatch->original_filename ?? $hashMatch->filename,
                'imported_at' => $hashMatch->created_at?->toIso8601String(),
                'error_title' => 'Duplicate ECN File Content Detected',
                'message' => "An ECN file with identical content was already imported as '{$hashMatch->filename}' on {$hashMatch->created_at?->format('Y-m-d H:i')}.",
            ];
        }

        $cleanFilename = strtolower(trim($filename));
        $nameMatch = EcnImportBatch::whereRaw('LOWER(TRIM(filename)) = ?', [$cleanFilename])
            ->orWhereRaw('LOWER(TRIM(original_filename)) = ?', [$cleanFilename])
            ->where('status', 'completed')
            ->first();

        if ($nameMatch) {
            return [
                'is_duplicate' => true,
                'is_duplicate_filename' => true,
                'duplicate_type' => 'filename',
                'matched_batch_id' => $nameMatch->id,
                'original_filename' => $nameMatch->original_filename ?? $nameMatch->filename,
                'imported_at' => $nameMatch->created_at?->toIso8601String(),
                'error_title' => 'Duplicate ECN Filename Detected',
                'message' => "An ECN file named '{$filename}' has already been imported on {$nameMatch->created_at?->format('Y-m-d H:i')}.",
            ];
        }

        return null;
    }

    /**
     * Parse and validate rows from ECN workbook.
     */
    public function extractAndValidateRows(string $path, string $filename): array
    {
        $errors = [];
        $warnings = [];
        $rows = [];

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors' => ['Failed to parse Excel file: ' . $e->getMessage()],
                'warnings' => [],
                'rows' => [],
                'sheet_name' => '',
                'summary' => $this->emptySummary(),
            ];
        }

        $sheet = $spreadsheet->getActiveSheet();
        $sheetName = $sheet->getTitle();
        $highestRow = $sheet->getHighestRow();
        $highestCol = $sheet->getHighestColumn();
        $highestColIndex = Coordinate::columnIndexFromString($highestCol);

        // Find header row dynamically (look for Project Code, ECN NO, Jig, Unit No, Part No, Side, Qty)
        $headerRow = null;
        $colMap = [];

        for ($r = 1; $r <= min(15, $highestRow); $r++) {
            $rowValues = [];
            for ($c = 1; $c <= $highestColIndex; $c++) {
                $val = trim((string)$sheet->getCellByColumnAndRow($c, $r)->getValue());
                if (!empty($val)) {
                    $rowValues[$c] = strtolower($val);
                }
            }

            $hasProj = false;
            $hasEcn = false;
            $hasJig = false;
            $hasPart = false;
            $tempMap = [];

            foreach ($rowValues as $c => $val) {
                if (preg_match('/project\s*code/i', $val)) {
                    $tempMap['project_code'] = $c;
                    $hasProj = true;
                } elseif (preg_match('/ecn\s*no/i', $val)) {
                    $tempMap['ecn_no'] = $c;
                    $hasEcn = true;
                } elseif (preg_match('/^jig/i', $val)) {
                    $tempMap['jig'] = $c;
                    $hasJig = true;
                } elseif (preg_match('/unit\s*no/i', $val)) {
                    $tempMap['unit_no'] = $c;
                } elseif (preg_match('/part\s*no/i', $val)) {
                    $tempMap['part_no'] = $c;
                    $hasPart = true;
                } elseif (preg_match('/^side/i', $val)) {
                    $tempMap['side'] = $c;
                } elseif (preg_match('/^qty/i', $val) || preg_match('/quantity/i', $val)) {
                    $tempMap['qty'] = $c;
                }
            }

            if ($hasProj && $hasEcn && $hasJig && $hasPart) {
                $headerRow = $r;
                $colMap = $tempMap;
                break;
            }
        }

        if (!$headerRow) {
            return [
                'success' => false,
                'errors' => ['Could not locate valid ECN header row. Expected columns: Project Code, ECN NO., Jig, Unit No., Part No., Side, Qty.'],
                'warnings' => [],
                'rows' => [],
                'sheet_name' => $sheetName,
                'summary' => $this->emptySummary(),
            ];
        }

        // Validate required column mappings
        $requiredCols = ['project_code', 'ecn_no', 'jig', 'unit_no', 'part_no', 'side', 'qty'];
        foreach ($requiredCols as $req) {
            if (!isset($colMap[$req])) {
                $errors[] = "Missing required ECN column: {$req}";
            }
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'errors' => $errors,
                'warnings' => [],
                'rows' => [],
                'sheet_name' => $sheetName,
                'summary' => $this->emptySummary(),
            ];
        }

        // Extract data rows
        for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
            $projCode = trim((string)$sheet->getCellByColumnAndRow($colMap['project_code'], $r)->getValue());
            $ecnNo = trim((string)$sheet->getCellByColumnAndRow($colMap['ecn_no'], $r)->getValue());
            $jigNo = trim((string)$sheet->getCellByColumnAndRow($colMap['jig'], $r)->getValue());
            $unitNo = trim((string)$sheet->getCellByColumnAndRow($colMap['unit_no'], $r)->getValue());
            $partNo = trim((string)$sheet->getCellByColumnAndRow($colMap['part_no'], $r)->getValue());
            $sideRaw = trim((string)$sheet->getCellByColumnAndRow($colMap['side'], $r)->getValue());
            $qtyRaw = $sheet->getCellByColumnAndRow($colMap['qty'], $r)->getValue();

            // Skip fully blank rows
            if (empty($projCode) && empty($ecnNo) && empty($jigNo) && empty($partNo)) {
                continue;
            }

            $rowErrors = [];

            if (empty($projCode)) {
                $rowErrors[] = "Row {$r}: Project Code is missing.";
            }

            if (empty($ecnNo)) {
                $rowErrors[] = "Row {$r}: ECN NO. is missing.";
            }

            if (empty($jigNo)) {
                $rowErrors[] = "Row {$r}: Jig is missing.";
            }

            if (empty($unitNo)) {
                $rowErrors[] = "Row {$r}: Unit No. is missing.";
            }

            if (empty($partNo)) {
                $rowErrors[] = "Row {$r}: Part No. is missing.";
            }

            $sideUpper = strtoupper($sideRaw);
            if (empty($sideRaw) || !in_array($sideUpper, self::VALID_ECN_SIDES)) {
                $rowErrors[] = "Row {$r}: Invalid Side '{$sideRaw}'. Allowed ECN sides are: " . implode(', ', self::VALID_ECN_SIDES) . '.';
            }

            $qty = is_numeric($qtyRaw) ? (int)$qtyRaw : null;
            if ($qty === null || $qty <= 0) {
                $rowErrors[] = "Row {$r}: Invalid Qty '{$qtyRaw}'. Must be a positive integer.";
            }

            if (!empty($rowErrors)) {
                $errors = array_merge($errors, $rowErrors);
                continue;
            }

            // Pad unit/part numbers if numeric strings (preserve standard formatting)
            $rows[] = [
                'row_number' => $r,
                'project_code' => $projCode,
                'ecn_number' => $ecnNo,
                'ecn_no' => $ecnNo,
                'jig_no' => $jigNo,
                'unit_no' => $unitNo,
                'part_no' => $partNo,
                'side' => $sideUpper,
                'side_display' => self::mapSideDisplay($sideUpper),
                'side_family' => self::mapSideFamily($sideUpper),
                'qty' => $qty,
            ];
        }

        $summary = [
            'total_rows' => count($rows) + count($errors),
            'valid_rows' => count($rows),
            'invalid_rows' => count($errors),
            'unique_projects' => count(array_unique(array_column($rows, 'project_code'))),
            'unique_ecn_numbers' => array_values(array_unique(array_column($rows, 'ecn_number'))),
            'total_qty' => array_sum(array_column($rows, 'qty')),
        ];

        return [
            'success' => empty($errors),
            'sheet_name' => $sheetName,
            'header_row' => $headerRow,
            'summary' => $summary,
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Preview and reconcile ECN rows against existing database state.
     */
    public function previewFromPath(string $path, string $filename): array
    {
        $duplicateInfo = $this->checkDuplicateFile($path, $filename);
        if ($duplicateInfo) {
            return [
                'success' => false,
                'is_duplicate' => true,
                'is_duplicate_filename' => $duplicateInfo['is_duplicate_filename'] ?? false,
                'error_title' => $duplicateInfo['error_title'],
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
        if (!empty($extracted['errors'])) {
            return [
                'success' => false,
                'filename' => $filename,
                'sheet' => $extracted['sheet_name'],
                'summary' => $extracted['summary'],
                'rows' => $extracted['rows'],
                'errors' => $extracted['errors'],
                'warnings' => $extracted['warnings'],
            ];
        }

        $rows = $extracted['rows'];
        $reconciliation = $this->reconcileImportRows($rows, $filename);

        return [
            'success' => empty($extracted['errors']) && empty($reconciliation['errors']),
            'filename' => $filename,
            'sheet' => $extracted['sheet_name'],
            'summary' => $extracted['summary'],
            'reconciliation' => $reconciliation['summary'],
            'matched_projects' => $reconciliation['matched_projects'],
            'ecn_numbers' => $extracted['summary']['unique_ecn_numbers'],
            'rows' => $reconciliation['classified_rows'],
            'conflicts' => $reconciliation['conflicts'],
            'errors' => array_merge($extracted['errors'], $reconciliation['errors']),
            'warnings' => array_merge($extracted['warnings'], $reconciliation['warnings']),
        ];
    }

    /**
     * Reconcile ECN rows against database.
     * ECN requires the Project to exist in the database!
     */
    public function reconcileImportRows(array $rows, string $filename): array
    {
        $projectGroups = collect($rows)->groupBy('project_code');
        $classifiedRows = [];
        $conflicts = [];
        $warnings = [];
        $errors = [];
        $matchedProjects = [];

        $totalAdded = 0;
        $totalUpdated = 0;
        $totalUnchanged = 0;
        $totalConflicts = 0;
        $quantityDelta = 0;

        foreach ($projectGroups as $sheetProjectCode => $projectRows) {
            $existingProject = $this->projectResolver->resolveProject($sheetProjectCode, $filename);

            if (!$existingProject) {
                $errors[] = "Project '{$sheetProjectCode}' not found in SpareTrack. ECN import requires the project and regular BOM to be imported first.";
                $matchedProjects[] = [
                    'project_code' => $sheetProjectCode,
                    'is_existing' => false,
                    'project_id' => null,
                    'project_name' => $sheetProjectCode,
                    'error' => 'Project not found in system',
                ];
                continue;
            }

            $projectId = $existingProject->id;
            $matchedProjects[] = [
                'project_code' => $sheetProjectCode,
                'is_existing' => true,
                'project_id' => $projectId,
                'project_name' => $existingProject->name,
            ];

            // Load existing ECN requirements for this project
            $existingReqs = EcnRequirement::where('project_id', $projectId)->get();
            $existingMap = [];

            foreach ($existingReqs as $req) {
                $key = $this->makeRequirementKey(
                    $req->ecn_number,
                    $req->jig_no,
                    $req->unit_no,
                    $req->part_no,
                    $req->side
                );
                $existingMap[$key] = $req;
            }

            foreach ($projectRows as $row) {
                $key = $this->makeRequirementKey(
                    $row['ecn_number'],
                    $row['jig_no'],
                    $row['unit_no'],
                    $row['part_no'],
                    $row['side']
                );

                $incomingQty = (int)$row['qty'];

                if (!isset($existingMap[$key])) {
                    // Brand new ECN requirement
                    $totalAdded++;
                    $quantityDelta += $incomingQty;
                    $classifiedRows[] = array_merge($row, [
                        'action' => 'ADD',
                        'status' => 'NEW',
                        'existing_qty' => null,
                        'incoming_qty' => $incomingQty,
                        'qty_diff' => $incomingQty,
                        'received_qty' => 0,
                        'reason' => 'New ECN requirement',
                    ]);
                } else {
                    $existingReq = $existingMap[$key];
                    $existingQty = (int)$existingReq->required_qty;
                    $receivedQty = (int)$existingReq->received_qty;

                    if ($incomingQty === $existingQty) {
                        $totalUnchanged++;
                        $classifiedRows[] = array_merge($row, [
                            'action' => 'SKIP',
                            'status' => 'UNCHANGED',
                            'existing_qty' => $existingQty,
                            'incoming_qty' => $incomingQty,
                            'qty_diff' => 0,
                            'received_qty' => $receivedQty,
                            'reason' => 'Quantity matches existing requirement',
                        ]);
                    } elseif ($incomingQty < $receivedQty) {
                        // Conflict: incoming required quantity is less than already received in store
                        $totalConflicts++;
                        $conflictData = array_merge($row, [
                            'action' => 'CONFLICT',
                            'status' => 'CONFLICT',
                            'existing_qty' => $existingQty,
                            'incoming_qty' => $incomingQty,
                            'qty_diff' => $incomingQty - $existingQty,
                            'received_qty' => $receivedQty,
                            'reason' => "Cannot reduce required qty to {$incomingQty} because {$receivedQty} pcs are already received.",
                        ]);
                        $classifiedRows[] = $conflictData;
                        $conflicts[] = $conflictData;
                    } else {
                        // Valid update
                        $totalUpdated++;
                        $quantityDelta += ($incomingQty - $existingQty);
                        $classifiedRows[] = array_merge($row, [
                            'action' => 'UPDATE',
                            'status' => 'UPDATED',
                            'existing_qty' => $existingQty,
                            'incoming_qty' => $incomingQty,
                            'qty_diff' => $incomingQty - $existingQty,
                            'received_qty' => $receivedQty,
                            'reason' => "Required quantity updated from {$existingQty} to {$incomingQty}",
                        ]);
                    }
                }
            }
        }

        $summary = [
            'total_rows' => count($rows),
            'added_count' => $totalAdded,
            'updated_count' => $totalUpdated,
            'unchanged_count' => $totalUnchanged,
            'conflict_count' => $totalConflicts,
            'quantity_delta' => $quantityDelta,
            'has_conflicts' => $totalConflicts > 0,
        ];

        return [
            'summary' => $summary,
            'matched_projects' => $matchedProjects,
            'classified_rows' => $classifiedRows,
            'conflicts' => $conflicts,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Execute transactional import.
     */
    public function importFromPath(string $path, string $filename, ?int $userId = null): array
    {
        $preview = $this->previewFromPath($path, $filename);

        if (!$preview['success']) {
            return [
                'success' => false,
                'message' => 'Import validation failed.',
                'errors' => $preview['errors'],
                'warnings' => $preview['warnings'] ?? [],
            ];
        }

        if (!empty($preview['conflicts'])) {
            return [
                'success' => false,
                'message' => 'Import contains quantity conflicts with already received parts.',
                'errors' => array_column($preview['conflicts'], 'reason'),
                'conflicts' => $preview['conflicts'],
            ];
        }

        $fileHash = $this->computeFileHash($path);
        $fileSize = file_exists($path) ? filesize($path) : null;
        $matchedProjects = $preview['matched_projects'];
        $projectId = $matchedProjects[0]['project_id'] ?? null;

        if (!$projectId) {
            return [
                'success' => false,
                'message' => 'Target project ID could not be determined.',
                'errors' => ['Target project not found.'],
            ];
        }

        return DB::transaction(function () use ($preview, $projectId, $filename, $fileHash, $fileSize, $userId) {
            // Create batch record
            $batch = EcnImportBatch::create([
                'project_id' => $projectId,
                'filename' => $filename,
                'original_filename' => $filename,
                'file_hash' => $fileHash,
                'file_size_bytes' => $fileSize,
                'imported_by' => $userId,
                'total_rows' => $preview['summary']['total_rows'] ?? count($preview['rows']),
                'successful_rows' => count($preview['rows']),
                'failed_rows' => 0,
                'added_rows_count' => $preview['reconciliation']['added_count'] ?? 0,
                'updated_rows_count' => $preview['reconciliation']['updated_count'] ?? 0,
                'skipped_rows_count' => $preview['reconciliation']['unchanged_count'] ?? 0,
                'conflict_rows_count' => 0,
                'ecn_numbers' => $preview['ecn_numbers'] ?? [],
                'diff_summary' => $preview['reconciliation'] ?? [],
                'status' => 'completed',
            ]);

            $added = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($preview['rows'] as $row) {
                if ($row['action'] === 'SKIP') {
                    $skipped++;
                    continue;
                }

                $ecnReq = EcnRequirement::updateOrCreate(
                    [
                        'project_id' => $projectId,
                        'ecn_number' => $row['ecn_number'],
                        'jig_no' => $row['jig_no'],
                        'unit_no' => $row['unit_no'],
                        'part_no' => $row['part_no'],
                        'side' => $row['side'],
                    ],
                    [
                        'ecn_import_batch_id' => $batch->id,
                        'side_display' => $row['side_display'],
                        'side_family' => $row['side_family'],
                        'required_qty' => (int)$row['qty'],
                    ]
                );

                if ($row['action'] === 'ADD') {
                    $added++;
                } else {
                    $updated++;
                }
            }

            Log::info("ECN Import completed successfully for project ID {$projectId}, batch ID {$batch->id}", [
                'added' => $added,
                'updated' => $updated,
                'skipped' => $skipped,
                'filename' => $filename,
            ]);

            return [
                'success' => true,
                'batch_id' => $batch->id,
                'project_id' => $projectId,
                'added_count' => $added,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'total_processed' => $added + $updated + $skipped,
                'ecn_numbers' => $preview['ecn_numbers'],
                'message' => "ECN import completed: {$added} new, {$updated} updated, {$skipped} unchanged.",
            ];
        });
    }

    protected function makeRequirementKey(string $ecnNo, string $jigNo, string $unitNo, string $partNo, string $side): string
    {
        return strtoupper(trim($ecnNo)) . '|' . strtoupper(trim($jigNo)) . '|' . strtoupper(trim($unitNo)) . '|' . strtoupper(trim($partNo)) . '|' . strtoupper(trim($side));
    }

    protected function emptySummary(): array
    {
        return [
            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'unique_projects' => 0,
            'unique_ecn_numbers' => [],
            'total_qty' => 0,
        ];
    }
}
