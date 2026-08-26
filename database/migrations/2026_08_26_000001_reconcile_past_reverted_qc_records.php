<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\ReceiptItem;
use App\Models\QcInspection;

return new class extends Migration
{
    public function up(): void
    {
        // Reconcile any past reverted items whose forward inspection records were decremented/depleted
        // but whose ReceiptItem status remained in 'qc_approved' or 'qc_rework'.
        $approvedItems = ReceiptItem::whereIn('status', ['qc_approved', 'qc_rework', 'qc_inspected'])->get();

        foreach ($approvedItems as $item) {
            $inspectedQty = (int) QcInspection::where('receipt_item_id', $item->id)
                ->sum(DB::raw('approved_quantity + rework_quantity + rejected_quantity'));

            $recQty = (int) $item->received_quantity;

            if ($inspectedQty < $recQty) {
                $unaccounted = $recQty - $inspectedQty;

                if ($inspectedQty === 0) {
                    // Entire quantity was reverted back to QC
                    $item->update([
                        'status' => 'qc_received',
                        'qc_received_at' => $item->qc_received_at ?? now(),
                    ]);
                } else {
                    // Partial quantity was reverted back to QC: keep inspected portion in approved, create qc_received slice for reverted portion
                    $item->update(['received_quantity' => $inspectedQty]);
                    $returnedItem = $item->replicate();
                    $returnedItem->received_quantity = $unaccounted;
                    $returnedItem->status = 'qc_received';
                    $returnedItem->qc_received_at = now();
                    $returnedItem->save();
                }
            }
        }
    }

    public function down(): void
    {
        // Irreversible zero-loss state restoration
    }
};
