<?php

namespace App\Console\Commands;

use App\Models\ReceiptItem;
use App\Models\Project;
use Illuminate\Console\Command;

class AuditMobileBopRecords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:mobile-bop {--project= : Filter by project code or ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit historical non-MFG (BOP and STD) receipt intake records across projects';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting historical non-MFG (BOP/STD) intake audit...');

        $query = ReceiptItem::query()
            ->with(['bomItem.project', 'receipt.receiver'])
            ->whereHas('bomItem', function ($q) {
                $q->whereIn('part_type', ['BOP', 'STD']);
            });

        $projectFilter = $this->option('project');
        if ($projectFilter) {
            $query->whereHas('bomItem.project', function ($q) use ($projectFilter) {
                if (is_numeric($projectFilter)) {
                    $q->where('id', (int) $projectFilter);
                } else {
                    $q->where('project_code', 'LIKE', "%{$projectFilter}%")
                      ->orWhere('name', 'LIKE', "%{$projectFilter}%");
                }
            });
        }

        $records = $query->orderBy('created_at', 'desc')->get();

        if ($records->isEmpty()) {
            $this->info('No BOP or STD receipt intake records found.');
            return Command::SUCCESS;
        }

        $rows = [];
        $bopCount = 0;
        $stdCount = 0;
        $totalQty = 0;

        foreach ($records as $item) {
            $partType = $item->bomItem?->part_type ?? 'UNKNOWN';
            if ($partType === 'BOP') {
                $bopCount++;
            } elseif ($partType === 'STD') {
                $stdCount++;
            }
            $totalQty += (int) $item->received_quantity;

            $rows[] = [
                'Item ID' => $item->id,
                'Receipt ID' => $item->receipt_id,
                'Project' => $item->bomItem?->project?->project_code ?? ($item->bomItem?->project?->name ?? 'N/A'),
                'Part No' => $item->bomItem?->standard_part_no ?? 'N/A',
                'Type' => $partType,
                'Side' => $item->side,
                'Qty' => $item->received_quantity,
                'Status' => $item->status,
                'Created At' => $item->created_at?->format('Y-m-d H:i:s') ?? 'N/A',
            ];
        }

        $this->table(
            ['Item ID', 'Receipt ID', 'Project', 'Part No', 'Type', 'Side', 'Qty', 'Status', 'Created At'],
            $rows
        );

        $this->newLine();
        $this->info("Audit Summary: Found {$records->count()} total non-MFG receipt records (BOP: {$bopCount}, STD: {$stdCount}, Total Qty: {$totalQty}).");
        $this->comment('Note: Existing production records remain intact in database to preserve historical integrity.');

        return Command::SUCCESS;
    }
}
