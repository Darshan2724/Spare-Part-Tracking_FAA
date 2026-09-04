<?php

namespace App\Http\Controllers;

use App\Events\PartReverted;
use App\Models\AssemblyRecord;
use App\Models\BomItem;
use App\Models\PaintRecord;
use App\Models\QcInspection;
use App\Models\ReceiptItem;
use App\Models\ReworkRecord;
use App\Models\WorkflowEvent;
use App\Models\EcnRequirement;
use App\Models\PurchaseQueueItem;
use App\Services\EcnWorkflowService;
use App\Services\EcnBulkSplitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkflowRevertController extends Controller
{
    public function __construct(
        protected EcnWorkflowService $ecnWorkflowService = new EcnWorkflowService(),
        protected EcnBulkSplitService $ecnBulkSplitService = new EcnBulkSplitService()
    ) {}
    /**
     * Get active revert-eligible segments for a given department, BOM item, and side.
     */
    public function getRevertOptions(Request $request)
    {
        $request->validate([
            'department' => ['required', 'in:store,qc,rework,paint,assembly,purchase'],
            'bom_item_id' => ['required', 'exists:bom_items,id'],
            'side' => ['required', 'in:RH,LH,COMMON'],
        ]);

        $dept = strtolower($request->input('department'));
        $bomItemId = (int) $request->input('bom_item_id');
        $side = $request->input('side');
        $user = $request->user();

        // Department Authorization Check
        if ($user && !$this->verifyDepartmentAuthorization($user, $dept)) {
            return response()->json([
                'success' => false,
                'message' => "Unauthorized access to {$dept} revert options for your role.",
                'options' => [],
                'total_revertible' => 0,
            ], 403);
        }

        $options = [];

        switch ($dept) {
            case 'store':
                $items = ReceiptItem::where('bom_item_id', $bomItemId)
                    ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
                    ->whereIn('status', ['pending_qc', 'store_received', 'received'])
                    ->orderBy('id', 'desc')
                    ->get();

                foreach ($items as $item) {
                    $inspected = (int) QcInspection::where('receipt_item_id', $item->id)->sum(DB::raw('approved_quantity + rejected_quantity + rework_quantity'));
                    $available = max(0, $item->received_quantity - $inspected);
                    if ($available > 0) {
                        $options[] = [
                            'source_type' => 'receipt_item',
                            'source_id' => $item->id,
                            'available_quantity' => $available,
                            'from_department' => 'STORE',
                            'to_department' => 'PENDING_ARRIVAL',
                            'target_label' => 'Pending Supplier Arrival',
                            'description' => "Store Stock Receipt #{$item->id} ({$available} pcs)",
                        ];
                    }
                }
                break;

            case 'qc':
                $items = ReceiptItem::where('bom_item_id', $bomItemId)
                    ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
                    ->where('status', 'qc_received')
                    ->orderBy('id', 'desc')
                    ->get();

                foreach ($items as $item) {
                    $inspected = (int) QcInspection::where('receipt_item_id', $item->id)->sum(DB::raw('approved_quantity + rejected_quantity + rework_quantity'));
                    $available = max(0, $item->received_quantity - $inspected);
                    if ($available > 0) {
                        $options[] = [
                            'source_type' => 'receipt_item',
                            'source_id' => $item->id,
                            'available_quantity' => $available,
                            'from_department' => 'QC',
                            'to_department' => 'STORE',
                            'target_label' => 'Store Bay',
                            'description' => "Arrived in QC from Store ({$available} pcs)",
                        ];
                    }
                }
                break;

            case 'rework':
                $inspections = QcInspection::where('bom_item_id', $bomItemId)
                    ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
                    ->where('rework_quantity', '>', 0)
                    ->orderBy('id', 'desc')
                    ->get();

                foreach ($inspections as $insp) {
                    $completed = (int) ReworkRecord::where('qc_inspection_id', $insp->id)->whereIn('status', ['completed', 'returned_to_qc'])->sum('quantity');
                    $available = max(0, $insp->rework_quantity - $completed);
                    if ($available > 0) {
                        $options[] = [
                            'source_type' => 'qc_inspection',
                            'source_id' => $insp->id,
                            'available_quantity' => $available,
                            'from_department' => 'REWORK',
                            'to_department' => 'QC',
                            'target_label' => 'Quality Control Bay',
                            'description' => "Routed to Rework from QC #{$insp->id} ({$available} pcs)",
                        ];
                    }
                }
                break;

            case 'paint':
                $inspections = QcInspection::where('bom_item_id', $bomItemId)
                    ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
                    ->where('approved_quantity', '>', 0)
                    ->where(fn($q) => $q->where('destination', 'PAINT')->orWhereNull('destination'))
                    ->orderBy('id', 'desc')
                    ->get();

                foreach ($inspections as $insp) {
                    $painted = (int) PaintRecord::where('qc_inspection_id', $insp->id)->sum('quantity');
                    $available = max(0, $insp->approved_quantity - $painted);
                    if ($available > 0) {
                        $options[] = [
                            'source_type' => 'qc_inspection',
                            'source_id' => $insp->id,
                            'available_quantity' => $available,
                            'from_department' => 'PAINT',
                            'to_department' => 'QC',
                            'target_label' => 'Quality Control Bay',
                            'description' => "Approved for Paint from QC #{$insp->id} ({$available} pcs)",
                        ];
                    }
                }
                break;

            case 'assembly':
                // Lineage 1: From Paint Records
                $paintRecords = PaintRecord::where('bom_item_id', $bomItemId)
                    ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
                    ->whereIn('status', ['completed', 'assembled'])
                    ->orderBy('id', 'desc')
                    ->get();

                foreach ($paintRecords as $p) {
                    $assembled = (int) AssemblyRecord::where('paint_record_id', $p->id)->sum('quantity');
                    $available = max(0, $p->quantity - $assembled);
                    if ($available > 0) {
                        $options[] = [
                            'source_type' => 'paint_record',
                            'source_id' => $p->id,
                            'available_quantity' => $available,
                            'from_department' => 'ASSEMBLY',
                            'to_department' => 'PAINT',
                            'target_label' => 'Paint Shop',
                            'description' => "Painted in Paint Shop #{$p->id} ({$available} pcs)",
                        ];
                    }
                }

                // Lineage 2: From Direct QC Inspections
                $directQc = QcInspection::where('bom_item_id', $bomItemId)
                    ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
                    ->where('approved_quantity', '>', 0)
                    ->where('destination', 'ASSEMBLY')
                    ->orderBy('id', 'desc')
                    ->get();

                foreach ($directQc as $insp) {
                    $assembled = (int) AssemblyRecord::where('qc_inspection_id', $insp->id)->sum('quantity');
                    $available = max(0, $insp->approved_quantity - $assembled);
                    if ($available > 0) {
                        $options[] = [
                            'source_type' => 'qc_inspection',
                            'source_id' => $insp->id,
                            'available_quantity' => $available,
                            'from_department' => 'ASSEMBLY',
                            'to_department' => 'QC',
                            'target_label' => 'Quality Control Bay',
                            'description' => "Direct QC Approval #{$insp->id} ({$available} pcs)",
                        ];
                    }
                }
                break;

            case 'purchase':
                $items = PurchaseQueueItem::where('bom_item_id', $bomItemId)
                    ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
                    ->whereIn('status', ['pending_purchase', 'exported'])
                    ->where('rejected_quantity', '>', 0)
                    ->orderBy('id', 'desc')
                    ->get();

                foreach ($items as $item) {
                    $avail = (int) $item->rejected_quantity;
                    if ($avail > 0) {
                        $options[] = [
                            'source_type' => 'purchase_queue_item',
                            'source_id' => $item->id,
                            'available_quantity' => $avail,
                            'from_department' => 'PURCHASE',
                            'to_department' => 'QC_ARRIVAL',
                            'target_label' => 'QC Arrival',
                            'description' => "QC Rejection in Purchase Queue #{$item->id} ({$avail} pcs)",
                        ];
                    }
                }
                break;
        }

        return response()->json([
            'success' => true,
            'department' => $dept,
            'bom_item_id' => $bomItemId,
            'side' => $side,
            'options' => $options,
            'total_revertible' => array_sum(array_column($options, 'available_quantity')),
        ]);
    }

    /**
     * Get all active department-wide revert-eligible items without requiring a Unit ID.
     */
    public function getRevertItems(Request $request)
    {
        $request->validate([
            'department' => ['required', 'in:store,qc,rework,paint,assembly,purchase'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'side' => ['nullable', 'in:RH,LH,COMMON'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $dept = strtolower($request->input('department'));
        $projectId = $request->input('project_id');
        $side = $request->input('side');
        $search = $request->input('search');
        $perPage = (int) ($request->input('per_page') ?? 100);
        $user = $request->user();

        // Department Authorization Check
        if ($user && !$this->verifyDepartmentAuthorization($user, $dept)) {
            return response()->json([
                'success' => false,
                'message' => "Unauthorized access to {$dept} revert items for your role.",
                'items' => [],
                'total' => 0,
            ], 403);
        }

        $applyBomFilters = function ($query) use ($projectId, $search) {
            if ($projectId) {
                $query->where('bom_items.project_id', $projectId);
            }
            if ($search) {
                $qStr = '%' . trim($search) . '%';
                $query->where(function ($sub) use ($qStr) {
                    $sub->where('bom_items.standard_part_no', 'ilike', $qStr)
                        ->orWhere('bom_items.item_no', 'ilike', $qStr)
                        ->orWhere('bom_items.jig_no', 'ilike', $qStr)
                        ->orWhere('bom_items.unit_no', 'ilike', $qStr)
                        ->orWhereHas('project', function ($pSub) use ($qStr) {
                            $pSub->where('name', 'ilike', $qStr)
                                 ->orWhere('project_code', 'ilike', $qStr);
                        });
                });
            }
        };

        $rawItems = [];

        switch ($dept) {
            case 'store':
                $q = ReceiptItem::with(['bomItem.project', 'bomItem.supplier'])
                    ->whereHas('bomItem', $applyBomFilters)
                    ->whereIn('status', ['pending_qc', 'store_received', 'received'])
                    ->where('received_quantity', '>', 0)
                    ->orderBy('id', 'desc');

                if ($side) {
                    $q->where(fn($sQ) => $sQ->where('side', $side)->orWhere('side', 'COMMON'));
                }

                $records = $q->take($perPage * 2)->get();
                foreach ($records as $r) {
                    $bom = $r->bomItem;
                    if (!$bom) continue;

                    $inspected = (int) QcInspection::where('receipt_item_id', $r->id)->sum(DB::raw('approved_quantity + rejected_quantity + rework_quantity'));
                    $avail = max(0, $r->received_quantity - $inspected);
                    if ($avail <= 0) continue;

                    $rawItems[] = [
                        'id' => "store_receipt_{$r->id}",
                        'bom_item_id' => $bom->id,
                        'standard_part_no' => $bom->standard_part_no,
                        'item_no' => $bom->item_no,
                        'side' => $r->side,
                        'project_id' => $bom->project_id,
                        'project_code' => $bom->project?->project_code ?? 'N/A',
                        'project_name' => $bom->project?->name ?? '',
                        'jig_no' => $bom->jig_no ?? ($bom->jig_name ?? ''),
                        'jig_name' => $bom->jig_no ?? ($bom->jig_name ?? ''),
                        'unit_no' => $bom->unit_no ?? '',
                        'supplier_name' => $bom->supplier?->name ?? 'Standard',
                        'available_quantity' => $avail,
                        'from_department' => 'STORE',
                        'to_department' => 'PENDING_ARRIVAL',
                        'target_label' => 'Pending Supplier Arrival',
                        'source_type' => 'receipt_item',
                        'source_id' => $r->id,
                        'revert_options' => [[
                            'source_type' => 'receipt_item',
                            'source_id' => $r->id,
                            'available_quantity' => $avail,
                            'from_department' => 'STORE',
                            'to_department' => 'PENDING_ARRIVAL',
                            'target_label' => 'Pending Supplier Arrival',
                            'description' => "Store Stock Receipt #{$r->id} ({$avail} pcs)",
                        ]],
                    ];
                    if (count($rawItems) >= $perPage) break;
                }
                break;

            case 'qc':
                $q = ReceiptItem::with(['bomItem.project', 'bomItem.supplier', 'qcInspections'])
                    ->whereHas('bomItem', $applyBomFilters)
                    ->where('status', 'qc_received')
                    ->orderBy('id', 'desc');

                if ($side) {
                    $q->where(fn($sQ) => $sQ->where('side', $side)->orWhere('side', 'COMMON'));
                }

                $records = $q->take($perPage * 2)->get();
                foreach ($records as $r) {
                    $bom = $r->bomItem;
                    if (!$bom) continue;
                    $inspected = (int) $r->qcInspections->sum(fn($insp) => $insp->approved_quantity + $insp->rejected_quantity + $insp->rework_quantity);
                    $avail = max(0, $r->received_quantity - $inspected);
                    if ($avail <= 0) continue;

                    $rawItems[] = [
                        'id' => "qc_receipt_{$r->id}",
                        'bom_item_id' => $bom->id,
                        'standard_part_no' => $bom->standard_part_no,
                        'item_no' => $bom->item_no,
                        'side' => $r->side,
                        'project_id' => $bom->project_id,
                        'project_code' => $bom->project?->project_code ?? 'N/A',
                        'project_name' => $bom->project?->name ?? '',
                        'jig_name' => $bom->jig_name ?? '',
                        'unit_no' => $bom->unit_no ?? '',
                        'supplier_name' => $bom->supplier?->name ?? 'Standard',
                        'available_quantity' => $avail,
                        'from_department' => 'QC',
                        'to_department' => 'STORE',
                        'target_label' => 'Store Bay',
                        'source_type' => 'receipt_item',
                        'source_id' => $r->id,
                        'revert_options' => [[
                            'source_type' => 'receipt_item',
                            'source_id' => $r->id,
                            'available_quantity' => $avail,
                            'from_department' => 'QC',
                            'to_department' => 'STORE',
                            'target_label' => 'Store Bay',
                            'description' => "Arrived in QC from Store ({$avail} pcs)",
                        ]],
                    ];
                    if (count($rawItems) >= $perPage) break;
                }
                break;

            case 'rework':
                $q = QcInspection::with(['bomItem.project', 'bomItem.supplier', 'reworkRecords'])
                    ->whereHas('bomItem', $applyBomFilters)
                    ->where('rework_quantity', '>', 0)
                    ->orderBy('id', 'desc');

                if ($side) {
                    $q->where(fn($sQ) => $sQ->where('side', $side)->orWhere('side', 'COMMON'));
                }

                $records = $q->take($perPage * 2)->get();
                foreach ($records as $insp) {
                    $bom = $insp->bomItem;
                    if (!$bom) continue;
                    $completed = (int) $insp->reworkRecords->whereIn('status', ['completed', 'returned_to_qc'])->sum('quantity');
                    $avail = max(0, $insp->rework_quantity - $completed);
                    if ($avail <= 0) continue;

                    $rawItems[] = [
                        'id' => "rework_insp_{$insp->id}",
                        'bom_item_id' => $bom->id,
                        'standard_part_no' => $bom->standard_part_no,
                        'item_no' => $bom->item_no,
                        'side' => $insp->side,
                        'project_id' => $bom->project_id,
                        'project_code' => $bom->project?->project_code ?? 'N/A',
                        'project_name' => $bom->project?->name ?? '',
                        'jig_name' => $bom->jig_name ?? '',
                        'unit_no' => $bom->unit_no ?? '',
                        'supplier_name' => $bom->supplier?->name ?? 'Standard',
                        'available_quantity' => $avail,
                        'from_department' => 'REWORK',
                        'to_department' => 'QC',
                        'target_label' => 'Quality Control Bay',
                        'source_type' => 'qc_inspection',
                        'source_id' => $insp->id,
                        'revert_options' => [[
                            'source_type' => 'qc_inspection',
                            'source_id' => $insp->id,
                            'available_quantity' => $avail,
                            'from_department' => 'REWORK',
                            'to_department' => 'QC',
                            'target_label' => 'Quality Control Bay',
                            'description' => "Routed to Rework from QC #{$insp->id} ({$avail} pcs)",
                        ]],
                    ];
                    if (count($rawItems) >= $perPage) break;
                }
                break;

            case 'paint':
                $q = QcInspection::with(['bomItem.project', 'bomItem.supplier', 'paintRecords'])
                    ->whereHas('bomItem', $applyBomFilters)
                    ->where('approved_quantity', '>', 0)
                    ->where(fn($pQ) => $pQ->where('destination', 'PAINT')->orWhereNull('destination'))
                    ->orderBy('id', 'desc');

                if ($side) {
                    $q->where(fn($sQ) => $sQ->where('side', $side)->orWhere('side', 'COMMON'));
                }

                $records = $q->take($perPage * 2)->get();
                foreach ($records as $insp) {
                    $bom = $insp->bomItem;
                    if (!$bom) continue;
                    $painted = (int) $insp->paintRecords->sum('quantity');
                    $avail = max(0, $insp->approved_quantity - $painted);
                    if ($avail <= 0) continue;

                    $rawItems[] = [
                        'id' => "paint_insp_{$insp->id}",
                        'bom_item_id' => $bom->id,
                        'standard_part_no' => $bom->standard_part_no,
                        'item_no' => $bom->item_no,
                        'side' => $insp->side,
                        'project_id' => $bom->project_id,
                        'project_code' => $bom->project?->project_code ?? 'N/A',
                        'project_name' => $bom->project?->name ?? '',
                        'jig_name' => $bom->jig_name ?? '',
                        'unit_no' => $bom->unit_no ?? '',
                        'supplier_name' => $bom->supplier?->name ?? 'Standard',
                        'available_quantity' => $avail,
                        'from_department' => 'PAINT',
                        'to_department' => 'QC',
                        'target_label' => 'Quality Control Bay',
                        'source_type' => 'qc_inspection',
                        'source_id' => $insp->id,
                        'revert_options' => [[
                            'source_type' => 'qc_inspection',
                            'source_id' => $insp->id,
                            'available_quantity' => $avail,
                            'from_department' => 'PAINT',
                            'to_department' => 'QC',
                            'target_label' => 'Quality Control Bay',
                            'description' => "Approved for Paint from QC #{$insp->id} ({$avail} pcs)",
                        ]],
                    ];
                    if (count($rawItems) >= $perPage) break;
                }
                break;

            case 'assembly':
                // Source 1: Paint Records
                $pQ = PaintRecord::with(['bomItem.project', 'bomItem.supplier', 'assemblyRecords'])
                    ->whereHas('bomItem', $applyBomFilters)
                    ->whereIn('status', ['completed', 'assembled'])
                    ->orderBy('id', 'desc');

                if ($side) {
                    $pQ->where(fn($sQ) => $sQ->where('side', $side)->orWhere('side', 'COMMON'));
                }

                $pRecords = $pQ->take($perPage * 2)->get();
                foreach ($pRecords as $p) {
                    $bom = $p->bomItem;
                    if (!$bom) continue;
                    $assembled = (int) $p->assemblyRecords->sum('quantity');
                    $avail = max(0, $p->quantity - $assembled);
                    if ($avail <= 0) continue;

                    $rawItems[] = [
                        'id' => "assembly_paint_{$p->id}",
                        'bom_item_id' => $bom->id,
                        'standard_part_no' => $bom->standard_part_no,
                        'item_no' => $bom->item_no,
                        'side' => $p->side,
                        'project_id' => $bom->project_id,
                        'project_code' => $bom->project?->project_code ?? 'N/A',
                        'project_name' => $bom->project?->name ?? '',
                        'jig_name' => $bom->jig_name ?? '',
                        'unit_no' => $bom->unit_no ?? '',
                        'supplier_name' => $bom->supplier?->name ?? 'Standard',
                        'available_quantity' => $avail,
                        'from_department' => 'ASSEMBLY',
                        'to_department' => 'PAINT',
                        'target_label' => 'Paint Shop',
                        'source_type' => 'paint_record',
                        'source_id' => $p->id,
                        'revert_options' => [[
                            'source_type' => 'paint_record',
                            'source_id' => $p->id,
                            'available_quantity' => $avail,
                            'from_department' => 'ASSEMBLY',
                            'to_department' => 'PAINT',
                            'target_label' => 'Paint Shop',
                            'description' => "Painted in Paint Shop #{$p->id} ({$avail} pcs)",
                        ]],
                    ];
                    if (count($rawItems) >= $perPage) break;
                }

                // Source 2: Direct QC Inspections
                if (count($rawItems) < $perPage) {
                    $qQ = QcInspection::with(['bomItem.project', 'bomItem.supplier', 'assemblyRecords'])
                        ->whereHas('bomItem', $applyBomFilters)
                        ->where('approved_quantity', '>', 0)
                        ->where('destination', 'ASSEMBLY')
                        ->orderBy('id', 'desc');

                    if ($side) {
                        $qQ->where(fn($sQ) => $sQ->where('side', $side)->orWhere('side', 'COMMON'));
                    }

                    $qRecords = $qQ->take($perPage * 2)->get();
                    foreach ($qRecords as $insp) {
                        $bom = $insp->bomItem;
                        if (!$bom) continue;
                        $assembled = (int) $insp->assemblyRecords->sum('quantity');
                        $avail = max(0, $insp->approved_quantity - $assembled);
                        if ($avail <= 0) continue;

                        $rawItems[] = [
                            'id' => "assembly_qc_{$insp->id}",
                            'bom_item_id' => $bom->id,
                            'standard_part_no' => $bom->standard_part_no,
                            'item_no' => $bom->item_no,
                            'side' => $insp->side,
                            'project_id' => $bom->project_id,
                            'project_code' => $bom->project?->project_code ?? 'N/A',
                            'project_name' => $bom->project?->name ?? '',
                            'jig_name' => $bom->jig_name ?? '',
                            'unit_no' => $bom->unit_no ?? '',
                            'supplier_name' => $bom->supplier?->name ?? 'Standard',
                            'available_quantity' => $avail,
                            'from_department' => 'ASSEMBLY',
                            'to_department' => 'QC',
                            'target_label' => 'Quality Control Bay',
                            'source_type' => 'qc_inspection',
                            'source_id' => $insp->id,
                            'revert_options' => [[
                                'source_type' => 'qc_inspection',
                                'source_id' => $insp->id,
                                'available_quantity' => $avail,
                                'from_department' => 'ASSEMBLY',
                                'to_department' => 'QC',
                                'target_label' => 'Quality Control Bay',
                                'description' => "Direct QC Approval #{$insp->id} ({$avail} pcs)",
                            ]],
                        ];
                        if (count($rawItems) >= $perPage) break;
                    }
                }
                break;

            case 'purchase':
                $q = PurchaseQueueItem::with(['project', 'bomItem.supplier', 'bomItem.project', 'rejectedBy'])
                    ->whereIn('status', ['pending_purchase', 'exported'])
                    ->where('rejected_quantity', '>', 0)
                    ->orderBy('id', 'desc');

                if ($projectId) {
                    $q->where('project_id', $projectId);
                }
                if ($side) {
                    $q->where(fn($sQ) => $sQ->where('side', $side)->orWhere('side', 'COMMON'));
                }
                if ($search) {
                    $qStr = '%' . trim($search) . '%';
                    $q->where(function ($sub) use ($qStr) {
                        $sub->where('standard_part_no', 'ilike', $qStr)
                            ->orWhere('rejection_reason', 'ilike', $qStr)
                            ->orWhereHas('bomItem', function ($bSub) use ($qStr) {
                                $bSub->where('standard_part_no', 'ilike', $qStr)
                                     ->orWhere('item_no', 'ilike', $qStr)
                                     ->orWhere('jig_no', 'ilike', $qStr)
                                     ->orWhere('unit_no', 'ilike', $qStr);
                            })
                            ->orWhereHas('project', function ($pSub) use ($qStr) {
                                $pSub->where('name', 'ilike', $qStr)
                                     ->orWhere('project_code', 'ilike', $qStr);
                            });
                    });
                }

                $records = $q->take($perPage * 2)->get();
                foreach ($records as $pq) {
                    $bom = $pq->bomItem;
                    $avail = (int) $pq->rejected_quantity;
                    if ($avail <= 0) continue;

                    $rawItems[] = [
                        'id' => "purchase_revert_{$pq->id}",
                        'bom_item_id' => $bom?->id ?? $pq->bom_item_id,
                        'purchase_queue_item_id' => $pq->id,
                        'standard_part_no' => $pq->standard_part_no ?? $bom?->standard_part_no,
                        'item_no' => $bom?->item_no ?? '',
                        'side' => $pq->side,
                        'project_id' => $pq->project_id ?? $bom?->project_id,
                        'project_code' => $pq->project?->project_code ?? ($bom?->project?->project_code ?? 'N/A'),
                        'project_name' => $pq->project?->name ?? ($bom?->project?->name ?? ''),
                        'jig_no' => $bom?->jig_no ?? ($bom?->jig_name ?? ''),
                        'jig_name' => $bom?->jig_no ?? ($bom?->jig_name ?? ''),
                        'unit_no' => $bom?->unit_no ?? '',
                        'supplier_name' => $bom?->supplier?->name ?? 'Standard',
                        'available_quantity' => $avail,
                        'rejected_quantity' => $avail,
                        'rejection_reason' => $pq->rejection_reason ?? 'QC Rejection',
                        'rejected_by_name' => $pq->rejectedBy?->name ?? 'QC Inspector',
                        'rejected_at' => $pq->rejected_at ? (is_string($pq->rejected_at) ? $pq->rejected_at : $pq->rejected_at->format('Y-m-d H:i')) : $pq->created_at?->format('Y-m-d H:i'),
                        'current_state' => 'QC Rejected (In Purchase)',
                        'from_department' => 'PURCHASE',
                        'to_department' => 'QC_ARRIVAL',
                        'target_label' => 'QC Arrival',
                        'source_type' => 'purchase_queue_item',
                        'source_id' => $pq->id,
                        'revert_options' => [[
                            'source_type' => 'purchase_queue_item',
                            'source_id' => $pq->id,
                            'available_quantity' => $avail,
                            'from_department' => 'PURCHASE',
                            'to_department' => 'QC_ARRIVAL',
                            'target_label' => 'QC Arrival',
                            'description' => "QC Rejection in Purchase #{$pq->id} ({$avail} pcs)",
                        ]],
                    ];
                    if (count($rawItems) >= $perPage) break;
                }
                break;
        }

        return response()->json([
            'success' => true,
            'department' => $dept,
            'total' => count($rawItems),
            'items' => $rawItems,
        ]);
    }

    /**
     * Execute a strict, transactional reverse workflow transition with row-level locks.
     */
    public function revert(Request $request)
    {
        $rawBomId = (string) $request->input('bom_item_id', '');
        $isEcn = $request->input('classification') === 'ECN' 
            || (bool) $request->input('is_ecn') 
            || str_starts_with(strtolower($rawBomId), 'ecn_')
            || str_starts_with(strtolower((string)$request->input('source_type', '')), 'ecn_');

        if ($isEcn) {
            $dept = strtolower($request->input('department'));
            $qty = (int) $request->input('quantity', 1);
            $reason = $request->input('reason') ?? 'Operational ECN revert';
            $recordId = (int) ($request->input('source_id') 
                ?? $request->input('record_id') 
                ?? $request->input('ecn_receipt_item_id') 
                ?? $request->input('ecn_workflow_record_id') 
                ?? (str_starts_with(strtolower($rawBomId), 'ecn_') ? str_replace('ecn_', '', strtolower($rawBomId)) : 0));

            return response()->json($this->ecnWorkflowService->revert($dept, $recordId, $qty, $reason, $request->user()?->id));
        }

        $request->validate([
            'department' => ['required', 'in:store,qc,rework,paint,assembly,purchase'],
            'bom_item_id' => ['required', 'exists:bom_items,id'],
            'side' => ['required', 'in:RH,LH,COMMON'],
            'quantity' => ['required', 'integer', 'min:1'],
            'source_type' => ['nullable', 'in:receipt_item,qc_inspection,paint_record,purchase_queue_item'],
            'source_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $dept = strtolower($request->input('department'));
        $user = $request->user();

        // Authorization Gate
        switch ($dept) {
            case 'store':
                $user?->hasAnyRole(['ADMIN', 'STORE']) ?: abort(403, 'Unauthorized. Store operational permission required.');
                break;
            case 'qc':
                $user?->hasAnyRole(['ADMIN', 'QC']) ?: abort(403, 'Unauthorized. QC operational permission required.');
                break;
            case 'rework':
                $user?->hasAnyRole(['ADMIN', 'REWORK', 'QC']) ?: abort(403, 'Unauthorized. Rework operational permission required.');
                break;
            case 'paint':
                $user?->hasAnyRole(['ADMIN', 'PAINT', 'QC']) ?: abort(403, 'Unauthorized. Paint operational permission required.');
                break;
            case 'assembly':
                $user?->hasAnyRole(['ADMIN', 'ASSEMBLY', 'QC']) ?: abort(403, 'Unauthorized. Assembly operational permission required.');
                break;
            case 'purchase':
                $user?->hasAnyRole(['ADMIN', 'PURCHASE', 'QC']) ?: abort(403, 'Unauthorized. Purchase operational permission required.');
                break;
        }

        $bomItemId = (int) $request->input('bom_item_id');
        $side = $request->input('side');
        $requestedQty = (int) $request->input('quantity');
        $sourceType = $request->input('source_type');
        $sourceId = $request->input('source_id') ? (int) $request->input('source_id') : null;
        $reason = $request->input('reason') ?? 'Operational workflow revert';

        return DB::transaction(function () use ($dept, $bomItemId, $side, $requestedQty, $sourceType, $sourceId, $reason, $user) {
            $bomItem = BomItem::with('project')->findOrFail($bomItemId);
            $projectId = $bomItem->project_id;

            switch ($dept) {
                case 'store':
                    return $this->executeStoreRevert($bomItem, $side, $requestedQty, $sourceId, $reason, $user);

                case 'qc':
                    return $this->executeQcRevert($bomItem, $side, $requestedQty, $sourceId, $reason, $user);

                case 'rework':
                    return $this->executeReworkRevert($bomItem, $side, $requestedQty, $sourceId, $reason, $user);

                case 'paint':
                    return $this->executePaintRevert($bomItem, $side, $requestedQty, $sourceId, $reason, $user);

                case 'assembly':
                    return $this->executeAssemblyRevert($bomItem, $side, $requestedQty, $sourceType, $sourceId, $reason, $user);

                case 'purchase':
                    return $this->executePurchaseRevert($bomItem, $side, $requestedQty, $sourceId, $reason, $user);

                default:
                    return response()->json(['success' => false, 'message' => 'Invalid department for revert.'], 422);
            }
        });
    }

    /**
     * Execute atomic bulk revert for multiple items in a department.
     */
    public function bulkRevert(Request $request)
    {
        $request->validate([
            'department' => ['required', 'in:store,qc,rework,paint,assembly,purchase'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.side' => ['nullable', 'in:RH,LH,COMMON'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $dept = strtolower($request->input('department'));
        $user = $request->user();

        // Authorization Gate
        switch ($dept) {
            case 'store':
                $user?->hasAnyRole(['ADMIN', 'STORE']) ?: abort(403, 'Unauthorized. Store operational permission required.');
                break;
            case 'qc':
                $user?->hasAnyRole(['ADMIN', 'QC']) ?: abort(403, 'Unauthorized. QC operational permission required.');
                break;
            case 'rework':
                $user?->hasAnyRole(['ADMIN', 'REWORK', 'QC']) ?: abort(403, 'Unauthorized. Rework operational permission required.');
                break;
            case 'paint':
                $user?->hasAnyRole(['ADMIN', 'PAINT', 'QC']) ?: abort(403, 'Unauthorized. Paint operational permission required.');
                break;
            case 'assembly':
                $user?->hasAnyRole(['ADMIN', 'ASSEMBLY', 'QC']) ?: abort(403, 'Unauthorized. Assembly operational permission required.');
                break;
            case 'purchase':
                $user?->hasAnyRole(['ADMIN', 'PURCHASE', 'QC']) ?: abort(403, 'Unauthorized. Purchase operational permission required.');
                break;
        }

        $rawItems = $request->input('items', []);
        $reason = $request->input('reason') ?? 'Bulk operational revert';
        $groups = $this->ecnBulkSplitService->classifySelection($rawItems);

        try {
            return DB::transaction(function () use ($dept, $groups, $reason, $user) {
                $results = [];
                $totalReverted = 0;

                // 1. Process Regular Reverts
                foreach ($groups['regular'] as $itemInput) {
                    $bomItemId = (int)($itemInput['bom_item_id'] ?? $itemInput['id'] ?? 0);
                    $bomItem = BomItem::with('project')->findOrFail($bomItemId);
                    $side = $itemInput['side'] ?? 'COMMON';
                    $qty = (int) ($itemInput['quantity'] ?? 1);
                    $sourceType = $itemInput['source_type'] ?? null;
                    $sourceId = isset($itemInput['source_id']) ? (int) $itemInput['source_id'] : null;

                    $res = null;
                    switch ($dept) {
                        case 'store':
                            $res = $this->executeStoreRevert($bomItem, $side, $qty, $sourceId, $reason, $user);
                            break;
                        case 'qc':
                            $res = $this->executeQcRevert($bomItem, $side, $qty, $sourceId, $reason, $user);
                            break;
                        case 'rework':
                            $res = $this->executeReworkRevert($bomItem, $side, $qty, $sourceId, $reason, $user);
                            break;
                        case 'paint':
                            $res = $this->executePaintRevert($bomItem, $side, $qty, $sourceId, $reason, $user);
                            break;
                        case 'assembly':
                            $res = $this->executeAssemblyRevert($bomItem, $side, $qty, $sourceType, $sourceId, $reason, $user);
                            break;
                        case 'purchase':
                            $res = $this->executePurchaseRevert($bomItem, $side, $qty, $sourceId, $reason, $user);
                            break;
                    }

                    $resData = json_decode($res->getContent(), true);
                    if (!$resData['success']) {
                        throw new \Exception($resData['message'] ?? 'Failed to revert item ' . $bomItem->standard_part_no);
                    }

                    $results[] = $resData;
                    $totalReverted += $qty;
                }

                // 2. Process ECN Reverts
                foreach ($groups['ecn'] as $eItem) {
                    $recordId = (int)($eItem['source_id'] ?? $eItem['record_id'] ?? $eItem['ecn_requirement_id'] ?? $eItem['id'] ?? 0);
                    $qty = (int)($eItem['quantity'] ?? 1);

                    $ecnRes = $this->ecnWorkflowService->revert($dept, $recordId, $qty, $reason, $user?->id);
                    if (empty($ecnRes['success'])) {
                        throw new \Exception($ecnRes['message'] ?? 'Failed to revert ECN item #' . $recordId);
                    }

                    $results[] = $ecnRes;
                    $totalReverted += $qty;
                }

                $totalCount = count($groups['regular']) + count($groups['ecn']);
                return response()->json([
                    'success' => true,
                    'message' => "Successfully bulk reverted {$totalReverted} units across {$totalCount} items in {$dept}.",
                    'total_reverted' => $totalReverted,
                    'regular_count' => count($groups['regular']),
                    'ecn_count' => count($groups['ecn']),
                    'items_count' => $totalCount,
                    'details' => $results,
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Store -> Pending Arrival Revert
     */
    protected function executeStoreRevert(BomItem $bomItem, string $side, int $qty, ?int $sourceId, string $reason, $user)
    {
        $query = ReceiptItem::where('bom_item_id', $bomItem->id)
            ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
            ->whereIn('status', ['pending_qc', 'store_received', 'received'])
            ->lockForUpdate();

        if ($sourceId) {
            $query->where('id', $sourceId);
        }

        $items = $query->get();
        $availableMap = [];

        foreach ($items as $item) {
            $inspected = (int) QcInspection::where('receipt_item_id', $item->id)->sum(DB::raw('approved_quantity + rejected_quantity + rework_quantity'));
            $avail = max(0, $item->received_quantity - $inspected);
            $availableMap[$item->id] = $avail;
        }

        $totalAvailable = (int) array_sum($availableMap);

        if ($totalAvailable < $qty) {
            return response()->json([
                'success' => false,
                'message' => "Cannot revert {$qty} units from Store. Only {$totalAvailable} uninspected units available."
            ], 422);
        }

        $qtyRemaining = $qty;
        foreach ($items as $item) {
            $avail = $availableMap[$item->id] ?? 0;
            if ($qtyRemaining <= 0 || $avail <= 0) continue;

            $consume = min($qtyRemaining, $avail);
            $qtyRemaining -= $consume;

            if ($item->received_quantity === $consume) {
                $item->update(['status' => 'reverted', 'received_quantity' => 0]);
            } else {
                $item->decrement('received_quantity', $consume);
            }

            WorkflowEvent::create([
                'bom_item_id' => $bomItem->id,
                'project_id' => $bomItem->project_id,
                'user_id' => $user->id,
                'event_type' => 'store_receipt_reverted',
                'side' => $side,
                'quantity' => -$consume,
                'previous_state' => 'store_received',
                'new_state' => 'pending',
                'remarks' => "Store receipt of {$consume} units reverted to pending supplier arrival. Reason: {$reason}",
            ]);
        }

        $this->broadcastRevert($bomItem, $side, $qty, 'STORE', 'PENDING_ARRIVAL');

        return response()->json([
            'success' => true,
            'message' => "Successfully reverted {$qty} units from Store back to Pending Arrival.",
            'reverted_quantity' => $qty,
            'from_department' => 'STORE',
            'to_department' => 'PENDING_ARRIVAL',
        ]);
    }

    /**
     * QC -> Store Revert
     */
    protected function executeQcRevert(BomItem $bomItem, string $side, int $qty, ?int $sourceId, string $reason, $user)
    {
        $query = ReceiptItem::where('bom_item_id', $bomItem->id)
            ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
            ->where('status', 'qc_received')
            ->lockForUpdate();

        if ($sourceId) {
            $query->where('id', $sourceId);
        }

        $items = $query->get();
        $availableMap = [];

        foreach ($items as $item) {
            $inspected = (int) QcInspection::where('receipt_item_id', $item->id)->sum(DB::raw('approved_quantity + rejected_quantity + rework_quantity'));
            $avail = max(0, $item->received_quantity - $inspected);
            $availableMap[$item->id] = $avail;
        }

        $totalAvailable = (int) array_sum($availableMap);

        if ($totalAvailable < $qty) {
            return response()->json([
                'success' => false,
                'message' => "Cannot revert {$qty} units from QC. Only {$totalAvailable} uninspected units available."
            ], 422);
        }

        $qtyRemaining = $qty;
        foreach ($items as $item) {
            $avail = $availableMap[$item->id] ?? 0;
            if ($qtyRemaining <= 0 || $avail <= 0) continue;

            $consume = min($qtyRemaining, $avail);
            $qtyRemaining -= $consume;

            if ($item->received_quantity === $consume) {
                $item->update(['status' => 'received']);
            } else {
                $item->decrement('received_quantity', $consume);
                // Create a received segment for the returned quantity in Store
                ReceiptItem::create([
                    'receipt_id' => $item->receipt_id,
                    'bom_item_id' => $item->bom_item_id,
                    'side' => $item->side,
                    'received_quantity' => $consume,
                    'status' => 'received',
                    'storage_location' => $item->storage_location,
                    'notes' => "Reverted from QC: {$reason}",
                ]);
            }

            WorkflowEvent::create([
                'bom_item_id' => $bomItem->id,
                'project_id' => $bomItem->project_id,
                'user_id' => $user->id,
                'event_type' => 'qc_reverted_to_store',
                'side' => $side,
                'quantity' => $consume,
                'previous_state' => 'qc_received',
                'new_state' => 'store_received',
                'remarks' => "QC physical arrival of {$consume} units reverted back to Store. Reason: {$reason}",
            ]);
        }

        $this->broadcastRevert($bomItem, $side, $qty, 'QC', 'STORE');

        return response()->json([
            'success' => true,
            'message' => "Successfully reverted {$qty} units from QC back to Store.",
            'reverted_quantity' => $qty,
            'from_department' => 'QC',
            'to_department' => 'STORE',
        ]);
    }

    /**
     * Rework -> QC Revert
     */
    protected function executeReworkRevert(BomItem $bomItem, string $side, int $qty, ?int $sourceId, string $reason, $user)
    {
        $query = QcInspection::where('bom_item_id', $bomItem->id)
            ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
            ->where('rework_quantity', '>', 0)
            ->lockForUpdate();

        if ($sourceId) {
            $query->where('id', $sourceId);
        }

        $inspections = $query->get();
        $availableMap = [];

        foreach ($inspections as $insp) {
            $completed = (int) ReworkRecord::where('qc_inspection_id', $insp->id)->whereIn('status', ['completed', 'returned_to_qc'])->sum('quantity');
            $avail = max(0, $insp->rework_quantity - $completed);
            $availableMap[$insp->id] = $avail;
        }

        $totalAvailable = (int) array_sum($availableMap);

        if ($totalAvailable < $qty) {
            return response()->json([
                'success' => false,
                'message' => "Cannot revert {$qty} units from Rework. Only {$totalAvailable} active rework units available."
            ], 422);
        }

        $qtyRemaining = $qty;
        foreach ($inspections as $insp) {
            $avail = $availableMap[$insp->id] ?? 0;
            if ($qtyRemaining <= 0 || $avail <= 0) continue;

            $consume = min($qtyRemaining, $avail);
            $qtyRemaining -= $consume;

            $insp->decrement('rework_quantity', $consume);
            $receiptItemToRestore = $insp->receiptItem;
            if ($insp->approved_quantity == 0 && $insp->rework_quantity == 0 && $insp->rejected_quantity == 0) {
                $insp->delete();
            }

            $this->restoreReceiptItemToQcReceived($bomItem, $side, $consume, $receiptItemToRestore);

            WorkflowEvent::create([
                'bom_item_id' => $bomItem->id,
                'project_id' => $bomItem->project_id,
                'user_id' => $user->id,
                'event_type' => 'rework_reverted_to_qc',
                'side' => $side,
                'quantity' => $consume,
                'previous_state' => 'qc_rework',
                'new_state' => 'qc_received',
                'remarks' => "Rework allocation of {$consume} units reverted back to QC inspection queue. Reason: {$reason}",
            ]);
        }

        $this->broadcastRevert($bomItem, $side, $qty, 'REWORK', 'QC');

        return response()->json([
            'success' => true,
            'message' => "Successfully reverted {$qty} units from Rework back to QC.",
            'reverted_quantity' => $qty,
            'from_department' => 'REWORK',
            'to_department' => 'QC',
        ]);
    }

    /**
     * Paint -> QC Revert
     */
    protected function executePaintRevert(BomItem $bomItem, string $side, int $qty, ?int $sourceId, string $reason, $user)
    {
        $query = QcInspection::where('bom_item_id', $bomItem->id)
            ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
            ->where('approved_quantity', '>', 0)
            ->where(fn($q) => $q->where('destination', 'PAINT')->orWhereNull('destination'))
            ->lockForUpdate();

        if ($sourceId) {
            $query->where('id', $sourceId);
        }

        $inspections = $query->get();
        $availableMap = [];

        foreach ($inspections as $insp) {
            $painted = (int) PaintRecord::where('qc_inspection_id', $insp->id)->sum('quantity');
            $avail = max(0, $insp->approved_quantity - $painted);
            $availableMap[$insp->id] = $avail;
        }

        $totalAvailable = (int) array_sum($availableMap);

        if ($totalAvailable < $qty) {
            return response()->json([
                'success' => false,
                'message' => "Cannot revert {$qty} units from Paint. Only {$totalAvailable} unpainted units available."
            ], 422);
        }

        $qtyRemaining = $qty;
        foreach ($inspections as $insp) {
            $avail = $availableMap[$insp->id] ?? 0;
            if ($qtyRemaining <= 0 || $avail <= 0) continue;

            $consume = min($qtyRemaining, $avail);
            $qtyRemaining -= $consume;

            $insp->decrement('approved_quantity', $consume);
            if (isset($insp->paint_quantity) && $insp->paint_quantity >= $consume) {
                $insp->decrement('paint_quantity', $consume);
            }

            $receiptItemToRestore = $insp->receiptItem;
            if ($insp->approved_quantity == 0 && $insp->rework_quantity == 0 && $insp->rejected_quantity == 0) {
                $insp->delete();
            }

            $this->restoreReceiptItemToQcReceived($bomItem, $side, $consume, $receiptItemToRestore);

            WorkflowEvent::create([
                'bom_item_id' => $bomItem->id,
                'project_id' => $bomItem->project_id,
                'user_id' => $user->id,
                'event_type' => 'paint_reverted_to_qc',
                'side' => $side,
                'quantity' => $consume,
                'previous_state' => 'qc_approved',
                'new_state' => 'qc_received',
                'remarks' => "Paint routing of {$consume} units reverted back to QC inspection queue. Reason: {$reason}",
            ]);
        }

        $this->broadcastRevert($bomItem, $side, $qty, 'PAINT', 'QC');

        return response()->json([
            'success' => true,
            'message' => "Successfully reverted {$qty} units from Paint back to QC.",
            'reverted_quantity' => $qty,
            'from_department' => 'PAINT',
            'to_department' => 'QC',
        ]);
    }

    /**
     * Assembly -> QC or Paint Revert
     */
    protected function executeAssemblyRevert(BomItem $bomItem, string $side, int $qty, ?string $sourceType, ?int $sourceId, string $reason, $user)
    {
        // 1. If explicit sourceType and sourceId provided
        if ($sourceType === 'paint_record' || ($sourceId && PaintRecord::where('id', $sourceId)->exists())) {
            return $this->revertAssemblyToPaint($bomItem, $side, $qty, $sourceId, $reason, $user);
        }

        if ($sourceType === 'qc_inspection' || ($sourceId && QcInspection::where('id', $sourceId)->exists())) {
            return $this->revertAssemblyToQc($bomItem, $side, $qty, $sourceId, $reason, $user);
        }

        // 2. Auto-resolve across Paint first, then QC
        $paintQuery = PaintRecord::where('bom_item_id', $bomItem->id)
            ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
            ->whereIn('status', ['completed', 'assembled'])
            ->lockForUpdate()
            ->get();

        $paintAvailable = 0;
        foreach ($paintQuery as $p) {
            $assembled = (int) AssemblyRecord::where('paint_record_id', $p->id)->sum('quantity');
            $paintAvailable += max(0, $p->quantity - $assembled);
        }

        if ($paintAvailable >= $qty) {
            return $this->revertAssemblyToPaint($bomItem, $side, $qty, null, $reason, $user);
        }

        return $this->revertAssemblyToQc($bomItem, $side, $qty, null, $reason, $user);
    }

    protected function revertAssemblyToPaint(BomItem $bomItem, string $side, int $qty, ?int $sourceId, string $reason, $user)
    {
        $query = PaintRecord::where('bom_item_id', $bomItem->id)
            ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
            ->whereIn('status', ['completed', 'assembled'])
            ->lockForUpdate();

        if ($sourceId) {
            $query->where('id', $sourceId);
        }

        $records = $query->get();
        $availableMap = [];

        foreach ($records as $p) {
            $assembled = (int) AssemblyRecord::where('paint_record_id', $p->id)->sum('quantity');
            $avail = max(0, $p->quantity - $assembled);
            $availableMap[$p->id] = $avail;
        }

        $totalAvailable = (int) array_sum($availableMap);

        if ($totalAvailable < $qty) {
            return response()->json([
                'success' => false,
                'message' => "Cannot revert {$qty} units from Assembly to Paint. Only {$totalAvailable} painted units available."
            ], 422);
        }

        $qtyRemaining = $qty;
        foreach ($records as $p) {
            $avail = $availableMap[$p->id] ?? 0;
            if ($qtyRemaining <= 0 || $avail <= 0) continue;

            $consume = min($qtyRemaining, $avail);
            $qtyRemaining -= $consume;

            if ($p->quantity === $consume) {
                $p->delete();
            } else {
                $p->decrement('quantity', $consume);
            }

            WorkflowEvent::create([
                'bom_item_id' => $bomItem->id,
                'project_id' => $bomItem->project_id,
                'user_id' => $user->id,
                'event_type' => 'assembly_reverted_to_paint',
                'side' => $side,
                'quantity' => $consume,
                'previous_state' => 'paint_completed',
                'new_state' => 'paint',
                'remarks' => "Assembly stock of {$consume} units reverted back to Paint Shop. Reason: {$reason}",
            ]);
        }

        $this->broadcastRevert($bomItem, $side, $qty, 'ASSEMBLY', 'PAINT');

        return response()->json([
            'success' => true,
            'message' => "Successfully reverted {$qty} units from Assembly back to Paint Shop.",
            'reverted_quantity' => $qty,
            'from_department' => 'ASSEMBLY',
            'to_department' => 'PAINT',
        ]);
    }

    protected function revertAssemblyToQc(BomItem $bomItem, string $side, int $qty, ?int $sourceId, string $reason, $user)
    {
        $query = QcInspection::where('bom_item_id', $bomItem->id)
            ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
            ->where('approved_quantity', '>', 0)
            ->where('destination', 'ASSEMBLY')
            ->lockForUpdate();

        if ($sourceId) {
            $query->where('id', $sourceId);
        }

        $inspections = $query->get();
        $availableMap = [];

        foreach ($inspections as $insp) {
            $assembled = (int) AssemblyRecord::where('qc_inspection_id', $insp->id)->sum('quantity');
            $avail = max(0, $insp->approved_quantity - $assembled);
            $availableMap[$insp->id] = $avail;
        }

        $totalAvailable = (int) array_sum($availableMap);

        if ($totalAvailable < $qty) {
            return response()->json([
                'success' => false,
                'message' => "Cannot revert {$qty} units from Assembly to QC. Only {$totalAvailable} direct QC units available."
            ], 422);
        }

        $qtyRemaining = $qty;
        foreach ($inspections as $insp) {
            $avail = $availableMap[$insp->id] ?? 0;
            if ($qtyRemaining <= 0 || $avail <= 0) continue;

            $consume = min($qtyRemaining, $avail);
            $qtyRemaining -= $consume;

            $insp->decrement('approved_quantity', $consume);
            if (isset($insp->assembly_quantity) && $insp->assembly_quantity >= $consume) {
                $insp->decrement('assembly_quantity', $consume);
            }

            $receiptItemToRestore = $insp->receiptItem;
            if ($insp->approved_quantity == 0 && $insp->rework_quantity == 0 && $insp->rejected_quantity == 0) {
                $insp->delete();
            }

            $this->restoreReceiptItemToQcReceived($bomItem, $side, $consume, $receiptItemToRestore);

            WorkflowEvent::create([
                'bom_item_id' => $bomItem->id,
                'project_id' => $bomItem->project_id,
                'user_id' => $user->id,
                'event_type' => 'assembly_reverted_to_qc',
                'side' => $side,
                'quantity' => $consume,
                'previous_state' => 'qc_approved',
                'new_state' => 'qc_received',
                'remarks' => "Direct Assembly allocation of {$consume} units reverted back to QC inspection queue. Reason: {$reason}",
            ]);
        }

        $this->broadcastRevert($bomItem, $side, $qty, 'ASSEMBLY', 'QC');

        return response()->json([
            'success' => true,
            'message' => "Successfully reverted {$qty} units from Assembly back to QC.",
            'reverted_quantity' => $qty,
            'from_department' => 'ASSEMBLY',
            'to_department' => 'QC',
        ]);
    }

    /**
     * Purchase -> QC Arrival Revert
     */
    public function executePurchaseRevert(BomItem $bomItem, string $side, int $qty, ?int $sourceId, string $reason, $user)
    {
        $query = PurchaseQueueItem::where('project_id', $bomItem->project_id)
            ->where(function ($q) use ($bomItem) {
                $q->where('bom_item_id', $bomItem->id)
                  ->orWhere('standard_part_no', $bomItem->standard_part_no);
            })
            ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
            ->whereIn('status', ['pending_purchase', 'exported'])
            ->where('rejected_quantity', '>', 0)
            ->lockForUpdate();

        if ($sourceId) {
            $query->where('id', $sourceId);
        }

        $purchaseItems = $query->orderBy('id', 'desc')->get();

        if ($purchaseItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => "No reversible QC-rejected purchase items found for {$bomItem->standard_part_no} ({$side}).",
            ], 422);
        }

        $totalAvail = (int) $purchaseItems->sum('rejected_quantity');
        if ($qty > $totalAvail) {
            return response()->json([
                'success' => false,
                'message' => "Requested revert quantity ({$qty}) exceeds available rejected purchase quantity ({$totalAvail}).",
            ], 422);
        }

        $qtyRemaining = $qty;
        $affectedRecords = [];

        foreach ($purchaseItems as $pqItem) {
            if ($qtyRemaining <= 0) break;

            $pAvail = (int) $pqItem->rejected_quantity;
            $takeQty = min($qtyRemaining, $pAvail);

            // 1. Decrement / update PurchaseQueueItem
            if ($takeQty === $pAvail) {
                $pqItem->update([
                    'rejected_quantity' => 0,
                    'status' => 'closed',
                    'remarks' => trim(($pqItem->remarks ? $pqItem->remarks . " | " : "") . "[REVERTED TO QC ARRIVAL] Reverted {$takeQty} pcs to QC Arrival by User #" . ($user?->id ?? 'sys') . ": {$reason}"),
                ]);
            } else {
                $pqItem->decrement('rejected_quantity', $takeQty);
                $pqItem->update([
                    'remarks' => trim(($pqItem->remarks ? $pqItem->remarks . " | " : "") . "[PARTIALLY REVERTED TO QC ARRIVAL] Reverted {$takeQty} pcs to QC Arrival by User #" . ($user?->id ?? 'sys') . ": {$reason}"),
                ]);
            }

            // 2. Decrement QcInspection.rejected_quantity
            $qcInsp = null;
            if ($pqItem->qc_inspection_id) {
                $qcInsp = QcInspection::lockForUpdate()->find($pqItem->qc_inspection_id);
                if ($qcInsp) {
                    $qcInsp->decrement('rejected_quantity', $takeQty);
                }
            }

            // 3. Restore ReceiptItem status to 'sent_to_qc' (QC Arrival)
            $receiptItem = null;
            if (!empty($qcInsp?->receipt_item_id)) {
                $receiptItem = ReceiptItem::lockForUpdate()->find($qcInsp->receipt_item_id);
            }
            if (!$receiptItem) {
                // Fallback: search for qc_rejected receipt item for this BOM item and side
                $receiptItem = ReceiptItem::where('bom_item_id', $bomItem->id)
                    ->where(fn($q) => $q->where('side', $side)->orWhere('side', 'COMMON'))
                    ->where('status', 'qc_rejected')
                    ->lockForUpdate()
                    ->orderByDesc('id')
                    ->first();
            }

            if ($receiptItem) {
                $rQty = (int) $receiptItem->received_quantity;
                if ($receiptItem->status === 'qc_rejected') {
                    if ($rQty === $takeQty) {
                        $receiptItem->update([
                            'status' => 'sent_to_qc',
                            'qc_received_at' => null,
                        ]);
                    } elseif ($rQty > $takeQty) {
                        $receiptItem->decrement('received_quantity', $takeQty);
                        $returnedItem = $receiptItem->replicate();
                        $returnedItem->received_quantity = $takeQty;
                        $returnedItem->status = 'sent_to_qc';
                        $returnedItem->qc_received_at = null;
                        $returnedItem->remarks = "Reverted from Purchase Rejection #{$pqItem->id}";
                        $returnedItem->save();
                    } else {
                        $receiptItem->update([
                            'status' => 'sent_to_qc',
                            'qc_received_at' => null,
                        ]);
                    }
                } else {
                    $returnedItem = $receiptItem->replicate();
                    $returnedItem->received_quantity = $takeQty;
                    $returnedItem->status = 'sent_to_qc';
                    $returnedItem->qc_received_at = null;
                    $returnedItem->remarks = "Reverted from Purchase Rejection #{$pqItem->id}";
                    $returnedItem->save();
                }
            } else {
                ReceiptItem::create([
                    'bom_item_id' => $bomItem->id,
                    'side' => $side,
                    'received_quantity' => $takeQty,
                    'status' => 'sent_to_qc',
                    'qc_received_at' => null,
                    'remarks' => "Reverted from Purchase Rejection #{$pqItem->id}",
                ]);
            }

            // 4. Create WorkflowEvent
            WorkflowEvent::create([
                'bom_item_id' => $bomItem->id,
                'project_id' => $bomItem->project_id,
                'user_id' => $user?->id,
                'event_type' => 'purchase_reverted_to_qc',
                'side' => $side,
                'quantity' => $takeQty,
                'previous_state' => 'qc_rejected',
                'new_state' => 'sent_to_qc',
                'remarks' => "Purchase Revert: Reverted {$takeQty} pcs from Purchase back to QC Arrival. Reason: {$reason}",
            ]);

            $affectedRecords[] = [
                'purchase_queue_item_id' => $pqItem->id,
                'reverted_quantity' => $takeQty,
            ];

            $qtyRemaining -= $takeQty;
        }

        $this->broadcastRevert($bomItem, $side, $qty, 'PURCHASE', 'QC_ARRIVAL');

        return response()->json([
            'success' => true,
            'message' => "Successfully reverted {$qty} units of {$bomItem->standard_part_no} from Purchase back to QC Arrival.",
            'reverted_quantity' => $qty,
            'from_department' => 'PURCHASE',
            'to_department' => 'QC_ARRIVAL',
            'target_label' => 'QC Arrival',
            'records' => $affectedRecords,
        ]);
    }

    /**
     * Helper to restore / create a ReceiptItem in 'qc_received' status for QC Inspection Queue.
     */
    protected function restoreReceiptItemToQcReceived(BomItem $bomItem, string $side, int $quantity, ?ReceiptItem $receiptItem = null): ReceiptItem
    {
        if ($receiptItem) {
            $recQty = (int) $receiptItem->received_quantity;
            if (in_array($receiptItem->status, ['qc_approved', 'qc_rework', 'qc_inspected'])) {
                if ($recQty === $quantity) {
                    $receiptItem->update([
                        'status' => 'qc_received',
                        'qc_received_at' => now(),
                    ]);
                    return $receiptItem;
                } elseif ($recQty > $quantity) {
                    $receiptItem->update(['received_quantity' => $recQty - $quantity]);
                    $returnedItem = $receiptItem->replicate();
                    $returnedItem->received_quantity = $quantity;
                    $returnedItem->status = 'qc_received';
                    $returnedItem->qc_received_at = now();
                    $returnedItem->save();
                    return $returnedItem;
                }
            } elseif ($receiptItem->status === 'qc_received') {
                // Already in qc_received state
                return $receiptItem;
            }

            // Create a replicated slice in qc_received
            $returnedItem = $receiptItem->replicate();
            $returnedItem->received_quantity = $quantity;
            $returnedItem->status = 'qc_received';
            $returnedItem->qc_received_at = now();
            $returnedItem->save();
            return $returnedItem;
        }

        // Check if an existing qc_received item exists for this BOM item and side
        $existingQcItem = ReceiptItem::where('bom_item_id', $bomItem->id)
            ->where('side', $side)
            ->where('status', 'qc_received')
            ->first();

        if ($existingQcItem) {
            $existingQcItem->increment('received_quantity', $quantity);
            return $existingQcItem;
        }

        // Fallback: create fresh ReceiptItem linked to BOM Item
        return ReceiptItem::create([
            'bom_item_id' => $bomItem->id,
            'side' => $side,
            'received_quantity' => $quantity,
            'status' => 'qc_received',
            'qc_received_at' => now(),
        ]);
    }

    protected function broadcastRevert(BomItem $bomItem, string $side, int $qty, string $fromDept, string $toDept)
    {
        try {
            broadcast(new PartReverted(
                $bomItem->id,
                $side,
                $qty,
                $fromDept,
                $toDept,
                $bomItem->project_id,
                $bomItem->jig_no,
                $bomItem->unit_no
            ))->toOthers();
        } catch (\Throwable $e) {
            Log::warning("Realtime broadcast for PartReverted failed: " . $e->getMessage());
        }
    }

    /**
     * Verify whether the authenticated user is authorized to perform operations in the requested department.
     */
    protected function verifyDepartmentAuthorization($user, string $dept): bool
    {
        if (!$user) return false;

        $roleName = strtoupper($user->role?->name ?? ($user->roles?->first()?->name ?? ''));

        // Super Admins, Admins, Directors, and Managers have cross-department oversight
        if (in_array($roleName, ['ADMIN', 'SUPER_ADMIN', 'MANAGER', 'DIRECTOR', 'SYSADMIN'])) {
            return true;
        }

        $deptRoleMap = [
            'store' => ['STORE', 'STORE_MANAGER', 'STORE_OPERATOR'],
            'qc' => ['QC', 'QC_MANAGER', 'QC_INSPECTOR'],
            'rework' => ['REWORK', 'REWORK_OPERATOR', 'QC'],
            'paint' => ['PAINT', 'PAINT_OPERATOR', 'PAINT_SHOP'],
            'assembly' => ['ASSEMBLY', 'ASSEMBLY_OPERATOR'],
            'purchase' => ['PURCHASE', 'PURCHASE_MANAGER', 'PURCHASE_OPERATOR', 'QC', 'QC_MANAGER'],
        ];

        $allowedRoles = $deptRoleMap[strtolower($dept)] ?? [];
        return in_array($roleName, $allowedRoles);
    }
}
