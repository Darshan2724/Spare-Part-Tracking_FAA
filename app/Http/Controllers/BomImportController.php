<?php

namespace App\Http\Controllers;

use App\Services\BomImportService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\BomImportBatch;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Models\PurchaseQueueItem;
use App\Models\WorkflowEvent;
use App\Services\SystemLogService;
use Illuminate\Support\Facades\DB;

class BomImportController extends Controller
{
    public function __construct(protected BomImportService $bomImportService)
    {
    }

    public function preview(Request $request)
    {
        $this->authorizeBomImport($request);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'max:20480'],
        ]);

        $file = $request->file('file');

        if ($file instanceof UploadedFile) {
            $temporaryPath = $file->getRealPath();
            if ($temporaryPath === false || !is_file($temporaryPath)) {
                return response()->json(['message' => 'Invalid upload file.'], 422);
            }

            return response()->json($this->bomImportService->previewFromPath($temporaryPath, $file->getClientOriginalName()));
        }

        return response()->json([
            'message' => 'No BOM file provided.',
        ], 422);
    }

    public function import(Request $request)
    {
        $this->authorizeBomImport($request);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'max:20480'],
            'filename' => ['nullable', 'string', 'max:255'],
            'project_code' => ['nullable', 'string', 'max:100'],
            'project_name' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');

        if ($file instanceof UploadedFile) {
            $temporaryPath = $file->getRealPath();
            if ($temporaryPath === false || !is_file($temporaryPath)) {
                return response()->json(['message' => 'Invalid upload file.'], 422);
            }

            $importData = $request->all();
            $clientName = $file->getClientOriginalName();
            $importData['filename'] = basename(str_replace('\\', '/', $request->input('filename') ?: $clientName));

            return response()->json($this->bomImportService->importFromPath($temporaryPath, $importData, $request->user()->id));
        }

        return response()->json([
            'message' => 'No BOM file provided.',
        ], 422);
    }

    protected function authorizeBomImport(Request $request): void
    {
        if (!$request->user()) {
            abort(401);
        }

        if (!$request->user()->hasAnyRole(['ADMIN', 'MANAGER'])) {
            abort(403, 'Unauthorized. Only Administrators and Managers can manage BOM imports.');
        }
    }

    public function history(Request $request)
    {
        $this->authorizeBomImport($request);

        $bomBatches = BomImportBatch::with(['project', 'importer'])
            ->get()
            ->map(function ($b) {
                $b->import_type = 'BOM';
                $b->bom_type = $b->bom_type ?: 'MFG';
                return $b;
            });

        $ecnBatches = \App\Models\EcnImportBatch::with(['project', 'importer'])
            ->get()
            ->map(function ($b) {
                $b->import_type = 'ECN';
                $b->bom_type = 'ECN';
                return $b;
            });

        $batches = $bomBatches->concat($ecnBatches)->sortByDesc('created_at')->values();

        return response()->json([
            'history' => $batches
        ]);
    }

    /**
     * Calculate and return pre-deletion impact preview for a specific BOM or ECN import batch.
     */
    public function impactPreview(Request $request, int $id)
    {
        $this->authorizeBomImport($request);

        // Check if this is an ECN import batch
        $ecnBatch = \App\Models\EcnImportBatch::with(['project', 'importer'])->find($id);
        if ($ecnBatch && ($request->input('type') === 'ECN' || $request->input('import_type') === 'ECN' || !BomImportBatch::where('id', $id)->exists())) {
            $projectId = $ecnBatch->project_id;
            $project = $ecnBatch->project;
            $reqQuery = \App\Models\EcnRequirement::where('ecn_import_batch_id', $ecnBatch->id);
            $reqIds = (clone $reqQuery)->pluck('id')->toArray();

            $reqCount = (clone $reqQuery)->count();
            $jigsCount = (clone $reqQuery)->whereNotNull('jig_no')->where('jig_no', '!=', '')->distinct('jig_no')->count('jig_no');
            $unitsCount = (clone $reqQuery)->whereNotNull('unit_no')->where('unit_no', '!=', '')->distinct('unit_no')->count('unit_no');

            $receiptsCount = !empty($reqIds) ? \App\Models\EcnReceiptItem::whereIn('ecn_requirement_id', $reqIds)->count() : 0;
            $workflowCount = !empty($reqIds) ? \App\Models\EcnWorkflowRecord::whereIn('ecn_requirement_id', $reqIds)->count() : 0;
            $totalOperationalRecords = $receiptsCount + $workflowCount;

            return response()->json([
                'batch' => [
                    'id' => $ecnBatch->id,
                    'filename' => $ecnBatch->filename,
                    'original_filename' => $ecnBatch->original_filename ?? $ecnBatch->filename,
                    'status' => $ecnBatch->status,
                    'total_rows' => $ecnBatch->total_rows,
                    'created_at' => $ecnBatch->created_at ? $ecnBatch->created_at->toISOString() : null,
                    'imported_by' => $ecnBatch->importer?->name ?? 'System',
                    'import_type' => 'ECN',
                ],
                'project' => $project ? [
                    'id' => $project->id,
                    'name' => $project->name,
                    'project_code' => $project->project_code,
                ] : null,
                'counts' => [
                    'jigs_count' => $jigsCount,
                    'units_count' => $unitsCount,
                    'unique_parts_count' => $reqCount,
                    'bom_requirements_count' => $reqCount,
                    'receipts_count' => 0,
                    'receipt_items_count' => $receiptsCount,
                    'qc_inspections_count' => $workflowCount,
                    'rework_records_count' => 0,
                    'paint_records_count' => 0,
                    'assembly_records_count' => 0,
                    'purchase_queue_count' => 0,
                    'total_operational_records' => $totalOperationalRecords,
                ],
                'has_operational_data' => $totalOperationalRecords > 0,
                'other_batches_count' => 1,
                'will_delete_project' => false,
                'is_ecn' => true,
            ]);
        }

        $batch = BomImportBatch::with(['project', 'importer'])->find($id);
        if (!$batch) {
            return response()->json(['message' => 'BOM Import history record not found.'], 404);
        }

        $project = $batch->project;
        $projectId = $batch->project_id;

        $uniquePartsCount = 0;
        $jigsCount = 0;
        $unitsCount = 0;
        $bomReqsCount = 0;
        $receiptsCount = 0;
        $receiptItemsCount = 0;
        $qcInspectionsCount = 0;
        $reworkRecordsCount = 0;
        $paintRecordsCount = 0;
        $assemblyRecordsCount = 0;
        $purchaseQueueCount = 0;

        if ($projectId) {
            $bomItemsQuery = BomItem::where('project_id', $projectId);
            $bomItemIds = (clone $bomItemsQuery)->pluck('id')->toArray();

            $uniquePartsCount = (clone $bomItemsQuery)->count();
            $jigsCount = (clone $bomItemsQuery)->whereNotNull('jig_no')->where('jig_no', '!=', '')->distinct('jig_no')->count('jig_no');
            $unitsCount = (clone $bomItemsQuery)->whereNotNull('unit_no')->where('unit_no', '!=', '')->distinct('unit_no')->count('unit_no');
            $bomReqsCount = !empty($bomItemIds) ? BomRequirement::whereIn('bom_item_id', $bomItemIds)->count() : 0;

            $receiptsCount = Receipt::where('project_id', $projectId)->count();
            $receiptItemsCount = !empty($bomItemIds) ? ReceiptItem::whereIn('bom_item_id', $bomItemIds)->count() : 0;
            $qcInspectionsCount = !empty($bomItemIds) ? QcInspection::whereIn('bom_item_id', $bomItemIds)->count() : 0;
            $reworkRecordsCount = !empty($bomItemIds) ? ReworkRecord::whereIn('bom_item_id', $bomItemIds)->count() : 0;
            $paintRecordsCount = !empty($bomItemIds) ? PaintRecord::whereIn('bom_item_id', $bomItemIds)->count() : 0;
            $assemblyRecordsCount = !empty($bomItemIds) ? AssemblyRecord::whereIn('bom_item_id', $bomItemIds)->count() : 0;
            $purchaseQueueCount = PurchaseQueueItem::where('project_id', $projectId)->orWhere(function ($q) use ($bomItemIds) {
                if (!empty($bomItemIds)) {
                    $q->whereIn('bom_item_id', $bomItemIds);
                }
            })->count();
        }

        $totalOperationalRecords = $receiptsCount + $receiptItemsCount + $qcInspectionsCount + $reworkRecordsCount + $paintRecordsCount + $assemblyRecordsCount + $purchaseQueueCount;
        $otherBatchesCount = $projectId ? BomImportBatch::where('project_id', $projectId)->where('id', '!=', $batch->id)->count() : 0;

        return response()->json([
            'batch' => [
                'id' => $batch->id,
                'filename' => $batch->filename,
                'original_filename' => $batch->original_filename ?? $batch->filename,
                'status' => $batch->status,
                'total_rows' => $batch->total_rows,
                'created_at' => $batch->created_at ? $batch->created_at->toISOString() : null,
                'imported_by' => $batch->importer?->name ?? 'System',
                'import_type' => 'BOM',
            ],
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
                'project_code' => $project->project_code,
            ] : null,
            'counts' => [
                'jigs_count' => $jigsCount,
                'units_count' => $unitsCount,
                'unique_parts_count' => $uniquePartsCount,
                'bom_requirements_count' => $bomReqsCount,
                'receipts_count' => $receiptsCount,
                'receipt_items_count' => $receiptItemsCount,
                'qc_inspections_count' => $qcInspectionsCount,
                'rework_records_count' => $reworkRecordsCount,
                'paint_records_count' => $paintRecordsCount,
                'assembly_records_count' => $assemblyRecordsCount,
                'purchase_queue_count' => $purchaseQueueCount,
                'total_operational_records' => $totalOperationalRecords,
            ],
            'has_operational_data' => $totalOperationalRecords > 0,
            'other_batches_count' => $otherBatchesCount,
            'will_delete_project' => ($otherBatchesCount === 0 && $project !== null),
            'is_ecn' => false,
        ]);
    }

    /**
     * Safely delete a specific BOM or ECN import batch inside a PostgreSQL transaction.
     */
    public function destroy(Request $request, int $id)
    {
        $this->authorizeBomImport($request);

        return DB::transaction(function () use ($request, $id) {
            // Check if this is an ECN import batch
            $ecnBatch = \App\Models\EcnImportBatch::with(['project', 'importer'])->lockForUpdate()->find($id);
            if ($ecnBatch && ($request->input('type') === 'ECN' || $request->input('import_type') === 'ECN' || !BomImportBatch::where('id', $id)->exists())) {
                $reqIds = \App\Models\EcnRequirement::where('ecn_import_batch_id', $ecnBatch->id)->pluck('id')->toArray();

                if (!empty($reqIds)) {
                    \App\Models\EcnWorkflowEvent::whereIn('ecn_requirement_id', $reqIds)->delete();
                    \App\Models\EcnWorkflowRecord::whereIn('ecn_requirement_id', $reqIds)->delete();
                    \App\Models\EcnReceiptItem::whereIn('ecn_requirement_id', $reqIds)->delete();
                }

                $deletedReqs = \App\Models\EcnRequirement::where('ecn_import_batch_id', $ecnBatch->id)->forceDelete();
                $ecnBatch->delete();

                event(new \App\Events\EcnUpdated([
                    'project_id' => $ecnBatch->project_id,
                    'event_type' => 'ECN_BATCH_DELETED',
                ]));

                return response()->json([
                    'success' => true,
                    'message' => "Successfully deleted ECN import batch and {$deletedReqs} ECN requirement(s). Regular BOM and Project remain untouched.",
                    'deleted_counts' => [
                        'ecn_requirements' => $deletedReqs,
                        'ecn_import_batches' => 1,
                        'projects' => 0,
                        'bom_items' => 0,
                    ],
                ]);
            }

            $batch = BomImportBatch::with(['project', 'importer'])->lockForUpdate()->find($id);

            if (!$batch) {
                return response()->json(['message' => 'Import history record not found.'], 404);
            }

            if ($batch->status === 'processing') {
                return response()->json(['message' => 'Cannot delete an import batch that is currently processing.'], 422);
            }

            $projectId = $batch->project_id;
            $project = $projectId ? Project::withTrashed()->find($projectId) : null;
            $batchFilename = $batch->filename;
            $batchId = $batch->id;
            $projectCode = $project?->project_code ?? 'N/A';
            $projectName = $project?->name ?? 'N/A';

            $deletedCounts = [
                'assembly_records' => 0,
                'paint_records' => 0,
                'rework_records' => 0,
                'qc_inspections' => 0,
                'receipt_items' => 0,
                'receipts' => 0,
                'purchase_queue_items' => 0,
                'workflow_events' => 0,
                'bom_requirements' => 0,
                'bom_items' => 0,
                'projects' => 0,
                'bom_import_batches' => 0,
            ];

            if ($projectId) {
                $otherBatchesCount = BomImportBatch::where('project_id', $projectId)->where('id', '!=', $batch->id)->count();

                if ($otherBatchesCount === 0) {
                    // Exclusive project: Clean up all project-specific records in strict FK order
                    $bomItemIds = BomItem::where('project_id', $projectId)->pluck('id')->toArray();

                    if (!empty($bomItemIds)) {
                        $deletedCounts['assembly_records'] = AssemblyRecord::whereIn('bom_item_id', $bomItemIds)->delete();
                        $deletedCounts['paint_records'] = PaintRecord::whereIn('bom_item_id', $bomItemIds)->delete();
                        $deletedCounts['rework_records'] = ReworkRecord::whereIn('bom_item_id', $bomItemIds)->delete();
                        $deletedCounts['qc_inspections'] = QcInspection::whereIn('bom_item_id', $bomItemIds)->delete();
                        $deletedCounts['receipt_items'] = ReceiptItem::whereIn('bom_item_id', $bomItemIds)->delete();
                        $deletedCounts['bom_requirements'] = BomRequirement::whereIn('bom_item_id', $bomItemIds)->delete();
                    }

                    $deletedCounts['receipts'] = Receipt::where('project_id', $projectId)->delete();
                    $deletedCounts['purchase_queue_items'] = PurchaseQueueItem::where('project_id', $projectId)
                        ->orWhere(function ($q) use ($bomItemIds) {
                            if (!empty($bomItemIds)) {
                                $q->whereIn('bom_item_id', $bomItemIds);
                            }
                        })->delete();

                    $deletedCounts['workflow_events'] = WorkflowEvent::where('project_id', $projectId)
                        ->orWhere(function ($q) use ($bomItemIds) {
                            if (!empty($bomItemIds)) {
                                $q->whereIn('bom_item_id', $bomItemIds);
                            }
                        })->delete();

                    $deletedCounts['supplier_assignment_histories'] = \App\Models\SupplierAssignmentHistory::where('project_id', $projectId)->delete();
                    $deletedCounts['supplier_assignments'] = \App\Models\SupplierAssignment::where('project_id', $projectId)->delete();

                    $deletedCounts['bom_items'] = BomItem::where('project_id', $projectId)->forceDelete();

                    if ($project) {
                        $project->forceDelete();
                        $deletedCounts['projects'] = 1;
                    }
                } else {
                    // Project is shared with other active import batches: delete only items linked to this batch
                    $batchBomItemIds = BomItem::where('project_id', $projectId)->where('import_batch_id', $batch->id)->pluck('id')->toArray();
                    if (!empty($batchBomItemIds)) {
                        $deletedCounts['assembly_records'] = AssemblyRecord::whereIn('bom_item_id', $batchBomItemIds)->delete();
                        $deletedCounts['paint_records'] = PaintRecord::whereIn('bom_item_id', $batchBomItemIds)->delete();
                        $deletedCounts['rework_records'] = ReworkRecord::whereIn('bom_item_id', $batchBomItemIds)->delete();
                        $deletedCounts['qc_inspections'] = QcInspection::whereIn('bom_item_id', $batchBomItemIds)->delete();
                        $deletedCounts['receipt_items'] = ReceiptItem::whereIn('bom_item_id', $batchBomItemIds)->delete();
                        $deletedCounts['bom_requirements'] = BomRequirement::whereIn('bom_item_id', $batchBomItemIds)->delete();
                        $deletedCounts['bom_items'] = BomItem::whereIn('id', $batchBomItemIds)->forceDelete();
                    }
                }
            }

            // Delete the BOM Import Batch record itself
            $batch->delete();
            $deletedCounts['bom_import_batches'] = 1;

            $user = $request->user();

            // Write Administrative Audit Log
            SystemLogService::log([
                'severity' => 'WARNING',
                'category' => 'admin_actions',
                'module' => 'BOM_IMPORT',
                'user_id' => $user?->id,
                'user_role' => $user?->roles?->first()?->name ?? 'ADMIN',
                'message' => "BOM_IMPORT_DELETED: Import Batch #{$batchId} ('{$batchFilename}') and Project '{$projectName}' ({$projectCode}) deleted by {$user?->name} ({$user?->email})",
                'details' => [
                    'event' => 'BOM_IMPORT_DELETED',
                    'import_batch_id' => $batchId,
                    'filename' => $batchFilename,
                    'project_id' => $projectId,
                    'project_code' => $projectCode,
                    'project_name' => $projectName,
                    'deleted_by_user_id' => $user?->id,
                    'deleted_by_name' => $user?->name,
                    'deleted_by_email' => $user?->email,
                    'deleted_counts' => $deletedCounts,
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => "BOM Import '{$batchFilename}' and associated Project '{$projectName}' ({$projectCode}) deleted successfully.",
                'deleted_batch_id' => $batchId,
                'deleted_project_id' => $projectId,
                'deleted_counts' => $deletedCounts,
            ]);
        });
    }
}
