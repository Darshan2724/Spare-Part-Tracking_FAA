<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\ReceiptItem;
use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Services\EcnWorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EcnBulkSplitService
{
    public function __construct(
        protected EcnWorkflowService $ecnWorkflowService = new EcnWorkflowService()
    ) {}

    /**
     * Partition mixed selection into REGULAR and ECN groups.
     */
    public function classifySelection(array $items): array
    {
        $regular = [];
        $ecn = [];

        foreach ($items as $item) {
            $rawId = (string)($item['id'] ?? $item['bom_item_id'] ?? $item['record_id'] ?? '');
            $isEcn = !empty($item['is_ecn']) ||
                     (isset($item['classification']) && strtoupper($item['classification']) === 'ECN') ||
                     !empty($item['ecn_requirement_id']) ||
                     !empty($item['ecn_receipt_item_id']) ||
                     str_starts_with(strtolower($rawId), 'ecn_');

            if ($isEcn) {
                $ecnReqId = $item['ecn_requirement_id'] ?? (str_starts_with(strtolower($rawId), 'ecn_') ? (int)str_replace('ecn_', '', strtolower($rawId)) : (int)($item['record_id'] ?? $item['id'] ?? 0));
                $ecn[] = array_merge($item, [
                    'classification' => 'ECN',
                    'is_ecn' => true,
                    'ecn_requirement_id' => $ecnReqId,
                ]);
            } else {
                $bomId = (int)($item['bom_item_id'] ?? $item['record_id'] ?? $item['id'] ?? 0);
                $regular[] = array_merge($item, [
                    'classification' => 'REGULAR',
                    'is_ecn' => false,
                    'bom_item_id' => $bomId,
                ]);
            }
        }

        return [
            'regular' => $regular,
            'ecn' => $ecn,
        ];
    }

    /**
     * Build compact summary for mixed bulk selection UI.
     */
    public function buildSplitSummary(array $items): array
    {
        $groups = $this->classifySelection($items);

        $regCount = count($groups['regular']);
        $regQty = (int)array_sum(array_map(fn($i) => (int)($i['quantity'] ?? $i['received_quantity'] ?? 1), $groups['regular']));

        $ecnCount = count($groups['ecn']);
        $ecnQty = (int)array_sum(array_map(fn($i) => (int)($i['quantity'] ?? $i['received_quantity'] ?? 1), $groups['ecn']));

        return [
            'total_items' => count($items),
            'regular' => [
                'count' => $regCount,
                'quantity' => $regQty,
            ],
            'ecn' => [
                'count' => $ecnCount,
                'quantity' => $ecnQty,
            ],
            'summary_text' => "Selected: " . count($items) . " parts | Regular: {$regCount} parts • {$regQty} pcs | ECN: {$ecnCount} parts • {$ecnQty} pcs",
        ];
    }

    /**
     * Process mixed bulk intake into Store within a single transaction.
     */
    public function processMixedStoreIntake(array $items, ?int $userId = null): array
    {
        $groups = $this->classifySelection($items);

        return DB::transaction(function () use ($groups, $userId) {
            $regSuccess = 0;
            $ecnSuccess = 0;
            $errors = [];

            // Process Regular Store intake
            foreach ($groups['regular'] as $item) {
                try {
                    $bomItemId = (int)($item['bom_item_id'] ?? $item['id']);
                    $side = $item['side'] ?? 'COMMON';
                    $qty = (int)($item['quantity'] ?? 1);
                    $remarks = $item['remarks'] ?? null;

                    $receiptItem = ReceiptItem::create([
                        'receipt_id' => $item['receipt_id'] ?? 1,
                        'bom_item_id' => $bomItemId,
                        'side' => $side,
                        'received_quantity' => $qty,
                        'status' => 'received',
                        'remarks' => $remarks,
                    ]);
                    $regSuccess++;
                } catch (\Throwable $e) {
                    $errors[] = "Regular item #{$item['id']}: " . $e->getMessage();
                    throw $e;
                }
            }

            // Process ECN Store intake
            foreach ($groups['ecn'] as $item) {
                try {
                    $reqId = (int)($item['ecn_requirement_id'] ?? $item['id']);
                    $qty = (int)($item['quantity'] ?? 1);
                    $remarks = $item['remarks'] ?? null;

                    $this->ecnWorkflowService->receiveStore($reqId, $qty, $remarks, $userId);
                    $ecnSuccess++;
                } catch (\Throwable $e) {
                    $errors[] = "ECN item #{$item['id']}: " . $e->getMessage();
                    throw $e;
                }
            }

            return [
                'success' => true,
                'regular_processed' => $regSuccess,
                'ecn_processed' => $ecnSuccess,
                'total_processed' => $regSuccess + $ecnSuccess,
                'message' => "Successfully processed {$regSuccess} regular and {$ecnSuccess} ECN parts.",
            ];
        });
    }

    /**
     * Process mixed bulk revert within a single transaction.
     */
    public function processMixedBulkRevert(string $department, array $items, ?int $userId = null): array
    {
        $groups = $this->classifySelection($items);

        return DB::transaction(function () use ($department, $groups, $userId) {
            $regSuccess = 0;
            $ecnSuccess = 0;

            // Process Regular Reverts
            foreach ($groups['regular'] as $item) {
                $recordId = (int)($item['source_id'] ?? $item['id']);
                $qty = (int)($item['quantity'] ?? 1);
                $remarks = $item['remarks'] ?? 'Bulk regular revert';

                // Call existing regular revert logic
                $dept = strtolower(trim($department));
                if ($dept === 'store' || $dept === 'qc') {
                    $recItem = ReceiptItem::lockForUpdate()->findOrFail($recordId);
                    $recItem->status = $dept === 'store' ? 'reverted' : 'received';
                    $recItem->save();
                }
                $regSuccess++;
            }

            // Process ECN Reverts
            foreach ($groups['ecn'] as $item) {
                $recordId = (int)($item['source_id'] ?? $item['id']);
                $qty = (int)($item['quantity'] ?? 1);
                $remarks = $item['remarks'] ?? 'Bulk ECN revert';

                $this->ecnWorkflowService->revert($department, $recordId, $qty, $remarks, $userId);
                $ecnSuccess++;
            }

            return [
                'success' => true,
                'regular_reverted' => $regSuccess,
                'ecn_reverted' => $ecnSuccess,
                'total_reverted' => $regSuccess + $ecnSuccess,
                'message' => "Successfully reverted {$regSuccess} regular and {$ecnSuccess} ECN parts.",
            ];
        });
    }
}
