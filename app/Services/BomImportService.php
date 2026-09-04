<?php

namespace App\Services;

use App\Models\BomImportBatch;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\ReceiptItem;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class BomImportService
{
    public function __construct(
        protected ProjectIdentityResolver $projectResolver = new ProjectIdentityResolver(),
        protected ?EcnImportService $ecnImportService = null
    ) {
        if ($this->ecnImportService === null) {
            $this->ecnImportService = new EcnImportService($this->projectResolver);
        }
    }

    /**
     * Inspect workbook header structure to auto-detect if this is an ECN workbook.
     */
    public function isEcnWorkbook(string $path): bool
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = min(25, (int)$sheet->getHighestRow());
            $highestColStr = $sheet->getHighestColumn();
            $highestCol = min(20, Coordinate::columnIndexFromString($highestColStr));

            for ($r = 1; $r <= $highestRow; $r++) {
                for ($c = 1; $c <= $highestCol; $c++) {
                    $val = trim((string)$sheet->getCellByColumnAndRow($c, $r)->getValue());
                    if (preg_match('/ecn\s*no/i', $val) || preg_match('/ecn\s*number/i', $val) || preg_match('/mfg\s*ecn\s*master\s*sheet/i', $val)) {
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    /**
     * Check if the filename has already been successfully imported.
     * Enforces that every BOM revision must use a different filename.
     */
    public function checkDuplicateFilename(?string $filename): ?array
    {
        if (empty($filename)) {
            return null;
        }

        $cleanFilename = basename(str_replace('\\', '/', $filename));

        $existingBatch = BomImportBatch::where('status', 'completed')
            ->where(function ($q) use ($cleanFilename) {
                $q->where('original_filename', $cleanFilename)
                  ->orWhere('filename', $cleanFilename);
            })
            ->with(['importer', 'project'])
            ->orderByDesc('created_at')
            ->first();

        if ($existingBatch) {
            $importDate = $existingBatch->created_at ? $existingBatch->created_at->format('d M Y, h:i A') : 'Previously';
            $importerName = $existingBatch->importer?->name ?? 'System Administrator';
            $projCode = $existingBatch->project?->project_code ?? 'Project';

            return [
                'is_duplicate' => true,
                'is_duplicate_filename' => true,
                'error_title' => 'Duplicate Filename',
                'batch_id' => $existingBatch->id,
                'original_filename' => $cleanFilename,
                'imported_at' => $importDate,
                'imported_by' => $importerName,
                'project_code' => $projCode,
                'message' => 'This BOM filename has already been imported. Please rename the revised BOM and upload it again.',
                'secondary_message' => 'Every BOM revision must use a different filename.',
            ];
        }

        return null;
    }

    /**
     * Check if the uploaded file (filename or content hash) has already been successfully imported.
     */
    public function checkDuplicateFile(string $path, ?string $filename = null): ?array
    {
        // 1. Mandatory exact filename uniqueness check
        if ($filename) {
            $duplicateFilename = $this->checkDuplicateFilename($filename);
            if ($duplicateFilename) {
                return $duplicateFilename;
            }
        }

        if (!is_file($path)) {
            return null;
        }

        // 2. Exact content hash duplicate check
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
                'is_duplicate_hash' => true,
                'error_title' => 'Duplicate File Content',
                'file_hash' => $fileHash,
                'batch_id' => $existingBatch->id,
                'original_filename' => $origName,
                'imported_at' => $importDate,
                'imported_by' => $importerName,
                'project_code' => $projCode,
                'message' => "This exact BOM file content has already been imported on {$importDate} by {$importerName} (under '{$origName}'). The same file cannot be imported again.",
                'secondary_message' => 'Every BOM revision must use a different filename and contain new or revised requirements.',
            ];
        }

        return null;
    }

    /**
     * Preview BOM from file path with intelligent project detection and reconciliation diffing.
     */
    public function previewFromPath(string $path, ?string $filename = null): array
    {
        $filename = $filename ? basename(str_replace('\\', '/', $filename)) : basename($path);

        // Check if workbook is ECN structure
        if ($this->isEcnWorkbook($path)) {
            $ecnPreview = $this->ecnImportService->previewFromPath($path, $filename);
            $ecnPreview['import_type'] = 'ECN';
            return $ecnPreview;
        }

        // 1. Immediate duplicate check before parsing
        $duplicateInfo = $this->checkDuplicateFile($path, $filename);
        if ($duplicateInfo) {
            return [
                'success' => false,
                'import_type' => 'REGULAR',
                'is_duplicate' => true,
                'is_duplicate_filename' => $duplicateInfo['is_duplicate_filename'] ?? false,
                'error_title' => $duplicateInfo['error_title'] ?? 'Duplicate Filename',
                'message' => $duplicateInfo['message'],
                'secondary_message' => $duplicateInfo['secondary_message'] ?? null,
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
                'import_type' => 'REGULAR',
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
            'success' => empty($extracted['errors']),
            'import_type' => 'REGULAR',
            'filename' => $filename,
            'sheet' => $extracted['sheet_name'],
            'summary' => $extracted['summary'],
            'reconciliation' => $reconciliation['summary'],
            'matched_projects' => $reconciliation['matched_projects'],
            'is_revision' => $reconciliation['is_revision'],
            'rows' => $reconciliation['classified_rows'],
            'conflicts' => $reconciliation['conflicts'],
            'errors' => $extracted['errors'],
            'warnings' => array_merge($extracted['warnings'], $reconciliation['warnings']),
        ];
    }

    /**
     * Reconcile parsed BOM rows against database state for matched projects.
     */
    public function reconcileImportRows(array $rows, string $filename): array
    {
        $projectGroups = collect($rows)->groupBy('project_code');
        $classifiedRows = [];
        $conflicts = [];
        $warnings = [];
        $matchedProjects = [];
        $isRevision = false;

        $totalNewJigs = 0;
        $totalNewUnits = 0;
        $totalNewParts = 0;
        $totalNewRequirements = 0;
        $totalUpdatedRequirements = 0;
        $totalUnchangedRequirements = 0;
        $totalConflicts = 0;
        $quantityDelta = 0;

        foreach ($projectGroups as $sheetProjectCode => $projectRows) {
            $existingProject = $this->projectResolver->resolveProject($sheetProjectCode, $filename);

            if (!$existingProject) {
                // Brand new project: all rows are NEW
                $uniqueJigsInProject = $projectRows->pluck('jig_no')->unique()->values()->toArray();
                $uniqueUnitsInProject = $projectRows->map(fn ($r) => $r['jig_no'] . '|' . $r['unit_no'])->unique()->count();
                $uniquePartsInProject = $projectRows->pluck('part_no')->unique()->count();

                $totalNewJigs += count($uniqueJigsInProject);
                $totalNewUnits += $uniqueUnitsInProject;
                $totalNewParts += $uniquePartsInProject;
                $totalNewRequirements += $projectRows->count();
                $quantityDelta += $projectRows->sum('qty');

                $matchedProjects[] = [
                    'project_code' => $sheetProjectCode,
                    'is_existing' => false,
                    'project_id' => null,
                    'project_name' => $sheetProjectCode,
                    'mode' => 'new_project',
                    'new_jigs_count' => count($uniqueJigsInProject),
                    'new_units_count' => $uniqueUnitsInProject,
                ];

                foreach ($projectRows as $row) {
                    $classifiedRows[] = array_merge($row, [
                        'action' => 'ADD',
                        'status' => 'NEW',
                        'existing_qty' => null,
                        'incoming_qty' => $row['qty'],
                        'qty_diff' => $row['qty'],
                        'received_qty' => 0,
                        'reason' => 'New requirement for new project',
                    ]);
                }
                continue;
            }

            // Existing Project detected!
            $isRevision = true;
            $existingProjectId = $existingProject->id;

            // Load existing BOM items and requirements in single query
            $existingItems = BomItem::where('project_id', $existingProjectId)
                ->with(['requirements'])
                ->get();

            // Load received quantities for these items from receipt_items
            $validReceiptStatuses = ['received', 'returned_to_store', 'sent_to_qc', 'qc_received', 'qc_approved', 'qc_rejected', 'paint_completed', 'assembly_completed'];
            $itemIds = $existingItems->pluck('id')->toArray();
            
            $receivedMap = [];
            if (!empty($itemIds)) {
                $receiptData = ReceiptItem::whereIn('bom_item_id', $itemIds)
                    ->whereIn('status', $validReceiptStatuses)
                    ->select('bom_item_id', 'side', DB::raw('SUM(received_quantity) as total_received'))
                    ->groupBy('bom_item_id', 'side')
                    ->get();

                foreach ($receiptData as $rd) {
                    $receivedMap[$rd->bom_item_id . '|' . $rd->side] = (int) $rd->total_received;
                }
            }

            // Build fast lookup map: key = jig_no|unit_no|standard_part_no
            $dbItemsMap = [];
            $existingJigs = [];
            $existingUnits = [];
            $existingPartSet = [];

            foreach ($existingItems as $item) {
                $itemKey = $this->makeItemKey($item->jig_no, $item->unit_no, $item->standard_part_no);
                $dbItemsMap[$itemKey] = $item;
                $existingJigs[$item->jig_no] = true;
                $existingUnits[$item->jig_no . '|' . $item->unit_no] = true;
                $existingPartSet[$item->standard_part_no] = true;
            }

            $jigSidesInDB = [];
            foreach ($existingItems as $item) {
                foreach ($item->requirements as $req) {
                    $jigSidesInDB[$item->jig_no][$req->side] = true;
                }
            }

            $incomingNewJigs = [];
            $incomingNewUnits = [];
            $incomingNewParts = [];

            foreach ($projectRows as $row) {
                $itemKey = $this->makeItemKey($row['jig_no'], $row['unit_no'], $row['part_no']);
                $side = $row['side'];
                $incomingQty = (int) ($row['qty'] ?? $row['quantity'] ?? 0);
                $jigKey = $row['jig_no'];

                // Structural Conflict Check (Part 22): Existing Jig side structure compatibility
                if (isset($jigSidesInDB[$jigKey])) {
                    $dbHasCommon = isset($jigSidesInDB[$jigKey]['COMMON']);
                    $dbHasSideSpecific = isset($jigSidesInDB[$jigKey]['LH']) || isset($jigSidesInDB[$jigKey]['RH']);

                    if ($dbHasCommon && in_array($side, ['LH', 'RH'], true)) {
                        $totalConflicts++;
                        $conflictObj = [
                            'row_number' => $row['row_number'] ?? 0,
                            'project_code' => $sheetProjectCode,
                            'jig_no' => $row['jig_no'],
                            'unit_no' => $row['unit_no'],
                            'part_no' => $row['part_no'],
                            'side' => $side,
                            'existing_qty' => null,
                            'incoming_qty' => $incomingQty,
                            'received_qty' => 0,
                            'reason' => "Structural Conflict: Existing Jig '{$jigKey}' is a COMMON Jig. Revised BOM contains {$side} side records for this Jig. Mixed side models on the same Jig are forbidden.",
                            'action_needed' => 'A Jig must be exclusively SIDE_SPECIFIC or COMMON.',
                        ];
                        $conflicts[] = $conflictObj;
                        $classifiedRows[] = array_merge($row, [
                            'action' => 'CONFLICT_REVIEW',
                            'status' => 'CONFLICT',
                            'existing_qty' => null,
                            'incoming_qty' => $incomingQty,
                            'qty_diff' => $incomingQty,
                            'received_qty' => 0,
                            'reason' => $conflictObj['reason'],
                        ]);
                        continue;
                    } elseif ($dbHasSideSpecific && $side === 'COMMON') {
                        $totalConflicts++;
                        $conflictObj = [
                            'row_number' => $row['row_number'] ?? 0,
                            'project_code' => $sheetProjectCode,
                            'jig_no' => $row['jig_no'],
                            'unit_no' => $row['unit_no'],
                            'part_no' => $row['part_no'],
                            'side' => $side,
                            'existing_qty' => null,
                            'incoming_qty' => $incomingQty,
                            'received_qty' => 0,
                            'reason' => "Structural Conflict: Existing Jig '{$jigKey}' is a SIDE_SPECIFIC (LH/RH) Jig. Revised BOM contains Common (blank side) records for this Jig. Mixed side models on the same Jig are forbidden.",
                            'action_needed' => 'A Jig must be exclusively SIDE_SPECIFIC or COMMON.',
                        ];
                        $conflicts[] = $conflictObj;
                        $classifiedRows[] = array_merge($row, [
                            'action' => 'CONFLICT_REVIEW',
                            'status' => 'CONFLICT',
                            'existing_qty' => null,
                            'incoming_qty' => $incomingQty,
                            'qty_diff' => $incomingQty,
                            'received_qty' => 0,
                            'reason' => $conflictObj['reason'],
                        ]);
                        continue;
                    }
                }

                if (!isset($existingJigs[$row['jig_no']])) {
                    $incomingNewJigs[$row['jig_no']] = true;
                }
                if (!isset($existingUnits[$row['jig_no'] . '|' . $row['unit_no']])) {
                    $incomingNewUnits[$row['jig_no'] . '|' . $row['unit_no']] = true;
                }
                if (!isset($existingPartSet[$row['part_no']])) {
                    $incomingNewParts[$row['part_no']] = true;
                }

                if (!isset($dbItemsMap[$itemKey])) {
                    // Entire BOM item is new
                    $totalNewRequirements++;
                    $quantityDelta += $incomingQty;

                    $classifiedRows[] = array_merge($row, [
                        'action' => 'ADD',
                        'status' => 'NEW',
                        'existing_qty' => null,
                        'incoming_qty' => $incomingQty,
                        'qty_diff' => $incomingQty,
                        'received_qty' => 0,
                        'reason' => 'New part requirement under existing project',
                    ]);
                    continue;
                }

                $matchedItem = $dbItemsMap[$itemKey];
                $existingReq = $matchedItem->requirements->firstWhere('side', $side);

                if (!$existingReq) {
                    // Existing item, but new side requirement
                    $totalNewRequirements++;
                    $quantityDelta += $incomingQty;

                    $classifiedRows[] = array_merge($row, [
                        'action' => 'ADD',
                        'status' => 'NEW',
                        'existing_qty' => null,
                        'incoming_qty' => $incomingQty,
                        'qty_diff' => $incomingQty,
                        'received_qty' => 0,
                        'reason' => "New {$side} requirement for existing part",
                    ]);
                    continue;
                }

                // Existing requirement found! Compare quantities
                $existingQty = (int) $existingReq->required_quantity;
                $receivedQty = $receivedMap[$matchedItem->id . '|' . $side] ?? 0;

                if ($incomingQty === $existingQty) {
                    // Exact duplicate row / unchanged requirement
                    $totalUnchangedRequirements++;

                    $classifiedRows[] = array_merge($row, [
                        'action' => 'SKIP',
                        'status' => 'UNCHANGED',
                        'existing_qty' => $existingQty,
                        'incoming_qty' => $incomingQty,
                        'qty_diff' => 0,
                        'received_qty' => $receivedQty,
                        'reason' => 'Identical requirement and quantity already in database',
                    ]);
                } elseif ($incomingQty > $existingQty) {
                    // Quantity increase: replace existing required quantity with new total
                    $diff = $incomingQty - $existingQty;
                    $totalUpdatedRequirements++;
                    $quantityDelta += $diff;

                    $classifiedRows[] = array_merge($row, [
                        'action' => 'UPDATE',
                        'status' => 'UPDATED',
                        'existing_qty' => $existingQty,
                        'incoming_qty' => $incomingQty,
                        'qty_diff' => $diff,
                        'received_qty' => $receivedQty,
                        'reason' => "Revised total requirement increased from {$existingQty} to {$incomingQty} (+{$diff})",
                    ]);
                } else {
                    // Quantity decrease ($incomingQty < $existingQty)
                    $diff = $incomingQty - $existingQty;

                    if ($incomingQty < $receivedQty) {
                        // CONFLICT: Cannot reduce requirement below already received physical parts
                        $totalConflicts++;
                        $conflictObj = [
                            'row_number' => $row['row_number'],
                            'project_code' => $sheetProjectCode,
                            'jig_no' => $row['jig_no'],
                            'unit_no' => $row['unit_no'],
                            'part_no' => $row['part_no'],
                            'side' => $side,
                            'existing_qty' => $existingQty,
                            'incoming_qty' => $incomingQty,
                            'received_qty' => $receivedQty,
                            'reason' => "Incoming requirement ({$incomingQty}) is less than quantity already received in Store ({$receivedQty}). Reduction would corrupt physical inventory records.",
                            'action_needed' => 'Manager review required before reducing below received count.',
                        ];

                        $conflicts[] = $conflictObj;

                        $classifiedRows[] = array_merge($row, [
                            'action' => 'CONFLICT_REVIEW',
                            'status' => 'CONFLICT',
                            'existing_qty' => $existingQty,
                            'incoming_qty' => $incomingQty,
                            'qty_diff' => $diff,
                            'received_qty' => $receivedQty,
                            'reason' => $conflictObj['reason'],
                        ]);
                    } else {
                        // Safe quantity decrease (received <= incoming)
                        $totalUpdatedRequirements++;
                        $quantityDelta += $diff;

                        $classifiedRows[] = array_merge($row, [
                            'action' => 'UPDATE',
                            'status' => 'UPDATED',
                            'existing_qty' => $existingQty,
                            'incoming_qty' => $incomingQty,
                            'qty_diff' => $diff,
                            'received_qty' => $receivedQty,
                            'reason' => "Revised requirement reduced from {$existingQty} to {$incomingQty} ({$diff}). {$receivedQty} already received.",
                        ]);
                    }
                }
            }

            $totalNewJigs += count($incomingNewJigs);
            $totalNewUnits += count($incomingNewUnits);
            $totalNewParts += count($incomingNewParts);

            $matchedProjects[] = [
                'project_code' => $sheetProjectCode,
                'is_existing' => true,
                'project_id' => $existingProject->id,
                'project_name' => $existingProject->name,
                'mode' => 'incremental_revision',
                'new_jigs' => array_keys($incomingNewJigs),
                'new_jigs_count' => count($incomingNewJigs),
                'new_units_count' => count($incomingNewUnits),
                'new_parts_count' => count($incomingNewParts),
            ];
        }

        $reconciliationSummary = [
            'is_revision' => $isRevision,
            'new_jigs_count' => $totalNewJigs,
            'new_units_count' => $totalNewUnits,
            'new_parts_count' => $totalNewParts,
            'new_requirements_count' => $totalNewRequirements,
            'updated_requirements_count' => $totalUpdatedRequirements,
            'unchanged_requirements_count' => $totalUnchangedRequirements,
            'conflict_count' => $totalConflicts,
            'quantity_delta' => $quantityDelta,
            'can_apply' => ($totalConflicts === 0),
        ];

        return [
            'is_revision' => $isRevision,
            'summary' => $reconciliationSummary,
            'matched_projects' => $matchedProjects,
            'classified_rows' => $classifiedRows,
            'conflicts' => $conflicts,
            'warnings' => $warnings,
        ];
    }

    /**
     * Import or merge BOM into PostgreSQL database transactionally.
     */
    public function importFromPath(string $path, array $data, int $userId): array
    {
        $filename = $data['filename'] ?? basename($path);
        $filename = basename(str_replace('\\', '/', $filename));

        // Check if import is ECN
        if (($data['import_type'] ?? '') === 'ECN' || $this->isEcnWorkbook($path)) {
            $ecnRes = $this->ecnImportService->importFromPath($path, $filename, $userId);
            $ecnRes['import_type'] = 'ECN';
            return $ecnRes;
        }

        // 1. Strict duplicate filename and content check before transaction
        $duplicateInfo = $this->checkDuplicateFile($path, $filename);
        if ($duplicateInfo) {
            return [
                'success' => false,
                'is_duplicate' => true,
                'is_duplicate_filename' => $duplicateInfo['is_duplicate_filename'] ?? false,
                'error_title' => $duplicateInfo['error_title'] ?? 'Duplicate Filename',
                'message' => $duplicateInfo['message'],
                'secondary_message' => $duplicateInfo['secondary_message'] ?? null,
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
            return DB::transaction(function () use ($rows, $filename, $userId, $extracted, $fileHash, $fileSize, $path) {
                // Double check duplicate filename inside transaction for concurrency safety
                $txDup = $this->checkDuplicateFilename($filename);
                if ($txDup) {
                    return [
                        'success' => false,
                        'is_duplicate' => true,
                        'is_duplicate_filename' => true,
                        'error_title' => 'Duplicate Filename',
                        'message' => $txDup['message'],
                        'secondary_message' => $txDup['secondary_message'] ?? null,
                        'duplicate_details' => $txDup,
                        'errors' => [$txDup['message']],
                        'warnings' => [],
                    ];
                }

                $reconciliation = $this->reconcileImportRows($rows, $filename);

                // If there are blocking conflicts, abort transaction
                if (!empty($reconciliation['conflicts'])) {
                    $conflictCount = count($reconciliation['conflicts']);
                    return [
                        'success' => false,
                        'has_conflicts' => true,
                        'message' => "Cannot apply BOM: {$conflictCount} quantity conflicts detected against existing received stock. Management review required.",
                        'conflicts' => $reconciliation['conflicts'],
                        'errors' => ["{$conflictCount} conflict(s) must be resolved before applying this revision."],
                    ];
                }

                $projectGroups = collect($rows)->groupBy('project_code');
                $projectCodesList = $projectGroups->keys()->values()->toArray();

                $affectedProjects = [];
                $totalAdded = 0;
                $totalUpdated = 0;
                $totalSkipped = 0;
                $totalQuantity = 0;
                $importType = $reconciliation['is_revision'] ? 'revision' : 'initial';

                foreach ($projectGroups as $sheetProjectCode => $projectRows) {
                    $existingProject = $this->projectResolver->resolveProject($sheetProjectCode, $filename);

                    if ($existingProject) {
                        // Acquire row lock to prevent concurrency race conditions
                        $project = Project::where('id', $existingProject->id)->lockForUpdate()->first();
                    } else {
                        $project = Project::firstOrCreate(
                            ['project_code' => $sheetProjectCode],
                            [
                                'name' => $sheetProjectCode,
                                'status' => 'active',
                                'created_by' => $userId,
                            ]
                        );
                    }

                    $affectedProjects[$project->id] = $project;

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
                        'import_type' => $importType,
                        'status' => 'completed',
                    ]);

                    // Index existing items for this project
                    $existingBomItems = BomItem::where('project_id', $project->id)->with('requirements')->get();
                    $itemsMap = [];
                    foreach ($existingBomItems as $item) {
                        $itemsMap[$this->makeItemKey($item->jig_no, $item->unit_no, $item->standard_part_no)] = $item;
                    }

                    $projectAdded = 0;
                    $projectUpdated = 0;
                    $projectSkipped = 0;

                    foreach ($projectRows as $row) {
                        $itemKey = $this->makeItemKey($row['jig_no'], $row['unit_no'], $row['part_no']);
                        $side = $row['side'];
                        $incomingQty = (int) $row['qty'];

                        if (!isset($itemsMap[$itemKey])) {
                            // Genuine new BomItem
                            $bomItem = BomItem::create([
                                'project_id' => $project->id,
                                'jig_no' => $row['jig_no'],
                                'unit_no' => $row['unit_no'],
                                'standard_part_no' => $row['part_no'],
                                'import_batch_id' => $batch->id,
                                'proj_spec_yn' => 'Y',
                            ]);

                            $bomItem->setRelation('requirements', collect());
                            $itemsMap[$itemKey] = $bomItem;

                            BomRequirement::create([
                                'bom_item_id' => $bomItem->id,
                                'side' => $side,
                                'required_quantity' => $incomingQty,
                            ]);

                            $projectAdded++;
                            $totalAdded++;
                            $totalQuantity += $incomingQty;
                        } else {
                            $bomItem = $itemsMap[$itemKey];
                            $existingReq = $bomItem->requirements->firstWhere('side', $side);

                            if (!$existingReq) {
                                // Existing item, new side requirement
                                BomRequirement::create([
                                    'bom_item_id' => $bomItem->id,
                                    'side' => $side,
                                    'required_quantity' => $incomingQty,
                                ]);

                                $projectAdded++;
                                $totalAdded++;
                                $totalQuantity += $incomingQty;
                            } else {
                                $existingQty = (int) $existingReq->required_quantity;

                                if ($incomingQty === $existingQty) {
                                    // Unchanged -> skip
                                    $projectSkipped++;
                                    $totalSkipped++;
                                } else {
                                    // Quantity update (replace with incoming revised total)
                                    $existingReq->required_quantity = $incomingQty;
                                    $existingReq->save();

                                    $projectUpdated++;
                                    $totalUpdated++;
                                    $totalQuantity += $incomingQty;
                                }
                            }
                        }
                    }

                    // Update batch with precise stats
                    $batch->update([
                        'added_rows_count' => $projectAdded,
                        'updated_rows_count' => $projectUpdated,
                        'skipped_rows_count' => $projectSkipped,
                        'conflict_rows_count' => 0,
                        'diff_summary' => [
                            'new_jigs' => $reconciliation['summary']['new_jigs_count'] ?? 0,
                            'new_units' => $reconciliation['summary']['new_units_count'] ?? 0,
                            'new_parts' => $reconciliation['summary']['new_parts_count'] ?? 0,
                            'quantity_delta' => $reconciliation['summary']['quantity_delta'] ?? 0,
                        ],
                    ]);
                }

                SystemLogService::log([
                    'severity' => 'INFO',
                    'category' => 'system_health_logs',
                    'module' => 'STORE',
                    'message' => "BOM {$importType} applied successfully: {$filename} (Added: {$totalAdded}, Updated: {$totalUpdated}, Skipped: {$totalSkipped})",
                    'details' => [
                        'filename' => $filename,
                        'file_hash' => $fileHash,
                        'import_type' => $importType,
                        'projects' => array_values(array_map(fn ($p) => $p->project_code, $affectedProjects)),
                        'added_requirements' => $totalAdded,
                        'updated_requirements' => $totalUpdated,
                        'skipped_requirements' => $totalSkipped,
                        'user_id' => $userId,
                    ],
                ]);

                return [
                    'success' => true,
                    'message' => "BOM {$importType} completed successfully: {$totalAdded} added, {$totalUpdated} updated, {$totalSkipped} unchanged.",
                    'filename' => $filename,
                    'file_hash' => $fileHash,
                    'import_type' => $importType,
                    'summary' => $extracted['summary'],
                    'reconciliation' => [
                        'added_count' => $totalAdded,
                        'updated_count' => $totalUpdated,
                        'skipped_count' => $totalSkipped,
                        'conflicts_count' => 0,
                        'is_revision' => $reconciliation['is_revision'],
                    ],
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
     * Create a composite lookup key for BOM items.
     */
    protected function makeItemKey(?string $jigNo, ?string $unitNo, ?string $partNo): string
    {
        return trim((string) $jigNo) . '|' . trim((string) $unitNo) . '|' . trim((string) $partNo);
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

        $lastProjectCode = '';
        for ($r = $headerRowIndex + 1; $r <= $highestRow; $r++) {
            $projectCodeRaw = trim((string) $sheet->getCell($headerMap['project_code'] . $r)->getValue());
            if ($projectCodeRaw !== '') {
                $lastProjectCode = $projectCodeRaw;
            }
            $projectCode = $projectCodeRaw !== '' ? $projectCodeRaw : $lastProjectCode;
            $jigNo = trim((string) $sheet->getCell($headerMap['jig_no'] . $r)->getValue());
            $unitNo = trim((string) $sheet->getCell($headerMap['unit_no'] . $r)->getValue());
            $partNo = trim((string) $sheet->getCell($headerMap['part_no'] . $r)->getValue());
            $sideRaw = trim((string) $sheet->getCell($headerMap['side'] . $r)->getValue());
            $qtyRaw = $sheet->getCell($headerMap['qty'] . $r)->getValue();

            // Skip completely empty rows
            if ($projectCodeRaw === '' && $jigNo === '' && $unitNo === '' && $partNo === '' && $sideRaw === '' && $qtyRaw === null) {
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

            // Side normalization & validation (blank/null is normalized to COMMON)
            $side = $this->normalizeSide($sideRaw);
            if ($side === null) {
                $rowErrors[] = "Row {$r}: Invalid Side '{$sideRaw}'. Must be RH (or R), LH (or L), or COMMON.";
            }

            // Qty validation
            $qty = $this->parseQuantity($qtyRaw);
            if ($qty === null || $qty <= 0) {
                $rowErrors[] = "Row {$r}: Qty must be a positive integer (found '{$qtyRaw}').";
            }

            // Duplicate detection within the same file
            $comboKey = "{$projectCode}|{$jigNo}|{$unitNo}|{$partNo}|{$side}";
            if (isset($seenCombinations[$comboKey])) {
                $prevRow = $seenCombinations[$comboKey];
                $rowErrors[] = "Row {$r}: Duplicate combination for Project '{$projectCode}', Jig '{$jigNo}', Unit '{$unitNo}', Part '{$partNo}', Side '{$side}' (first seen on Row {$prevRow}).";
            } else {
                $seenCombinations[$comboKey] = $r;
            }

            if (!empty($rowErrors)) {
                $errors = array_merge($errors, $rowErrors);
            } else {
                $validRows[] = [
                    'row_number' => $r,
                    'project_code' => $projectCode,
                    'jig_no' => $jigNo,
                    'unit_no' => $unitNo,
                    'part_no' => $partNo,
                    'side' => $side,
                    'qty' => $qty,
                ];

                $uniqueProjects[$projectCode] = true;
                $uniqueJigs[$jigNo] = true;
                $uniqueUnits[$unitNo] = true;
                $uniqueParts[$partNo] = true;
                $sideStats[$side]['count']++;
                $sideStats[$side]['qty'] += $qty;
                $totalRequiredQuantity += $qty;
            }
        }

        // Jig Exclusivity Validation (Part 2 & Part 20): A Jig must not contain both Common and Side-Specific parts
        $jigSideSets = [];
        foreach ($validRows as $vRow) {
            $jigSideSets[$vRow['jig_no']][$vRow['side']] = true;
        }
        foreach ($jigSideSets as $jigKey => $sidesSeen) {
            $hasCommon = isset($sidesSeen['COMMON']);
            $hasSideSpecific = isset($sidesSeen['LH']) || isset($sidesSeen['RH']);
            if ($hasCommon && $hasSideSpecific) {
                $errors[] = "Jig '{$jigKey}' contains inconsistent side structures: contains both Common (blank side) and Side-Specific (LH/RH) parts. A Jig must be exclusively SIDE_SPECIFIC or COMMON.";
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
    public function normalizeSide(mixed $value): ?string
    {
        if ($value === null) {
            return 'COMMON';
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return 'COMMON';
        }

        $upper = strtoupper($trimmed);

        if (in_array($upper, ['R', 'RH', 'RIGHT', 'RA', 'AR'], true)) {
            return 'RH';
        }
        if (in_array($upper, ['L', 'LH', 'LEFT', 'LA', 'AL'], true)) {
            return 'LH';
        }
        if (in_array($upper, ['C', 'COM', 'COMMON', 'BOTH', 'NULL', 'BLANK', 'NONE'], true)) {
            return 'COMMON';
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
