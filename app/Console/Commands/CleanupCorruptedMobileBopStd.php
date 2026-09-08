<?php

namespace App\Console\Commands;

use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\PurchaseQueueItem;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CleanupCorruptedMobileBopStd extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleanup:corrupted-mobile-bop-std {--commit : Actually execute the transactional deletion} {--project= : Optional project filter, defaults to FA-273 or all non-MFG}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely clean up corrupted BOP/STD receipt items and child QC/PQ records created by historical mobile intake bug';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting corrupted mobile BOP/STD data identification & cleanup...');

        $query = ReceiptItem::query()
            ->with(['bomItem.project', 'receipt', 'qcInspections'])
            ->whereHas('bomItem', function ($q) {
                $q->whereIn('part_type', ['BOP', 'STD']);
            });

        $projectFilter = $this->option('project') ?: 'FA-273';
        if ($projectFilter && $projectFilter !== 'all') {
            $query->whereHas('bomItem.project', function ($q) use ($projectFilter) {
                if (is_numeric($projectFilter)) {
                    $q->where('id', (int) $projectFilter);
                } else {
                    $q->where('project_code', 'LIKE', "%{$projectFilter}%")
                      ->orWhere('name', 'LIKE', "%{$projectFilter}%");
                }
            });
        }

        $receiptItems = $query->orderBy('id')->get();

        if ($receiptItems->isEmpty()) {
            $this->info('No corrupted BOP/STD receipt items found.');
            return Command::SUCCESS;
        }

        $receiptItemIds = $receiptItems->pluck('id')->all();
        $receiptIds = $receiptItems->pluck('receipt_id')->unique()->filter()->values()->all();

        // Check child QC inspections
        $qcInspections = QcInspection::whereIn('receipt_item_id', $receiptItemIds)->get();
        $qcInspectionIds = $qcInspections->pluck('id')->all();

        // Check child Purchase Queue entries
        $purchaseQueueItems = PurchaseQueueItem::whereIn('qc_inspection_id', $qcInspectionIds)->get();
        $purchaseQueueIds = $purchaseQueueItems->pluck('id')->all();

        // Check if receipts contain any MFG items (must NOT delete receipts that have MFG items)
        $mfgItemCountInReceipts = ReceiptItem::whereIn('receipt_id', $receiptIds)
            ->whereHas('bomItem', function ($q) {
                $q->where('part_type', 'MFG');
            })
            ->count();

        // Display summary table
        $this->newLine();
        $this->info("Found {$receiptItems->count()} corrupted non-MFG receipt items across " . count($receiptIds) . " receipts:");

        $tableRows = [];
        foreach ($receiptItems as $item) {
            $tableRows[] = [
                'ID' => $item->id,
                'Receipt ID' => $item->receipt_id,
                'Project' => $item->bomItem?->project?->project_code ?? 'N/A',
                'Part Type' => $item->bomItem?->part_type ?? 'N/A',
                'Part No' => $item->bomItem?->standard_part_no ?? 'N/A',
                'Side' => $item->side,
                'Qty' => $item->received_quantity,
                'Status' => $item->status,
                'QC Count' => $item->qcInspections->count(),
                'Created At' => $item->created_at?->format('Y-m-d H:i:s') ?? 'N/A',
            ];
        }
        $this->table(['ID', 'Receipt ID', 'Project', 'Part Type', 'Part No', 'Side', 'Qty', 'Status', 'QC Count', 'Created At'], $tableRows);

        $this->newLine();
        $this->info("Associated Records Summary:");
        $this->line("- Receipt Items to delete: " . count($receiptItemIds) . " (IDs: " . implode(', ', $receiptItemIds) . ")");
        $this->line("- QC Inspections to delete: " . count($qcInspectionIds) . " (IDs: " . implode(', ', $qcInspectionIds) . ")");
        $this->line("- Purchase Queue items to delete: " . count($purchaseQueueIds) . " (IDs: " . implode(', ', $purchaseQueueIds) . ")");
        $this->line("- Receipt Headers: " . count($receiptIds) . " (IDs: " . implode(', ', $receiptIds) . ")");
        $this->line("- Legitimate MFG items in these receipts: {$mfgItemCountInReceipts}");

        if ($mfgItemCountInReceipts > 0) {
            $this->warn("SAFETY CHECK: Some receipts contain legitimate MFG items. Only the non-MFG items will be removed, receipts will NOT be deleted.");
        }

        // Export Snapshot Backup
        $backupDir = storage_path('app/backups');
        if (!File::exists($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
        }

        $timestamp = date('Ymd_His');
        $backupFile = "{$backupDir}/corrupted_mobile_bop_std_snapshot_{$timestamp}.json";

        $snapshotData = [
            'timestamp' => now()->toIso8601String(),
            'project_filter' => $projectFilter,
            'receipt_item_ids' => $receiptItemIds,
            'receipt_items' => $receiptItems->toArray(),
            'qc_inspection_ids' => $qcInspectionIds,
            'qc_inspections' => $qcInspections->toArray(),
            'purchase_queue_ids' => $purchaseQueueIds,
            'purchase_queue' => $purchaseQueueItems->toArray(),
            'receipt_headers' => Receipt::whereIn('id', $receiptIds)->get()->toArray(),
        ];

        File::put($backupFile, json_encode($snapshotData, JSON_PRETTY_PRINT));
        $this->info("Backup snapshot created successfully at: {$backupFile}");

        if (!$this->option('commit')) {
            $this->newLine();
            $this->comment('DRY RUN COMPLETE. No records were modified.');
            $this->comment('Run with --commit to execute the transactional cleanup:');
            $this->comment('  php artisan cleanup:corrupted-mobile-bop-std --commit');
            return Command::SUCCESS;
        }

        $this->newLine();
        $this->warn('Executing TRANSACTIONAL CLEANUP with --commit...');

        DB::beginTransaction();
        try {
            // 1. Delete Purchase Queue items
            if (!empty($purchaseQueueIds)) {
                $deletedPq = PurchaseQueueItem::whereIn('id', $purchaseQueueIds)->delete();
                $this->line("  -> Deleted {$deletedPq} Purchase Queue records.");
            }

            // 2. Delete QC Inspections
            if (!empty($qcInspectionIds)) {
                $deletedQc = QcInspection::whereIn('id', $qcInspectionIds)->delete();
                $this->line("  -> Deleted {$deletedQc} QC Inspection records.");
            }

            // 3. Delete Receipt Items
            $deletedItems = ReceiptItem::whereIn('id', $receiptItemIds)->delete();
            $this->line("  -> Deleted {$deletedItems} corrupted Receipt Item records.");

            // 4. Delete Empty Receipts (receipts that have no remaining receipt items)
            $emptyReceipts = Receipt::whereIn('id', $receiptIds)
                ->whereDoesntHave('items')
                ->get();
            $emptyReceiptIds = $emptyReceipts->pluck('id')->all();

            if (!empty($emptyReceiptIds)) {
                $deletedReceipts = Receipt::whereIn('id', $emptyReceiptIds)->delete();
                $this->line("  -> Deleted {$deletedReceipts} empty Receipt headers (IDs: " . implode(', ', $emptyReceiptIds) . ").");
            }

            DB::commit();
            $this->info('Cleanup committed successfully in database transaction!');
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Failed to cleanup corrupted records: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
