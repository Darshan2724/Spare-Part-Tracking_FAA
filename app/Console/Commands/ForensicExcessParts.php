<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ForensicExcessParts extends Command
{
    protected $signature   = 'forensic:excess-parts {project_id? : Project ID to audit (leave blank for all)}';
    protected $description = 'FORENSIC AUDIT: Identify exact source of excess received parts vs BOM requirement';

    public function handle(): int
    {
        $this->newLine();
        $this->line('======================================================================');
        $this->line('  CRITICAL FORENSIC AUDIT -- EXCESS PARTS INVESTIGATION');
        $this->line('  MODE: READ-ONLY  |  NO DATA MODIFICATIONS WILL BE MADE');
        $this->line('======================================================================');
        $this->newLine();

        $projectId = $this->argument('project_id');
        $pidFilter = $projectId ? "AND p.id = " . (int)$projectId : '';
        $pidFilterBi = $projectId ? "AND bi.project_id = " . (int)$projectId : '';

        // ----------------------------------------------------------------
        // PHASE 1: BOM vs Receipt totals per project
        // ----------------------------------------------------------------
        $this->line('--- PHASE 1: BOM Total vs Raw Receipt Total Per Project ---');

        $projectTotals = DB::select("
            SELECT
                p.id AS project_id,
                p.project_code,
                p.name AS project_name,
                COALESCE(SUM(br.required_quantity), 0) AS bom_total,
                COALESCE(SUM(CASE WHEN ri.status IN (
                    'received','sent_to_qc','qc_received','qc_approved',
                    'qc_rework','qc_inspected','paint_completed',
                    'assembly_completed','returned_to_store'
                ) THEN ri.received_quantity ELSE 0 END), 0) AS raw_received_total,
                COALESCE(SUM(CASE WHEN ri.status IN (
                    'received','sent_to_qc','qc_received','qc_approved',
                    'qc_rework','qc_inspected','paint_completed',
                    'assembly_completed','returned_to_store'
                ) THEN ri.received_quantity ELSE 0 END), 0) -
                COALESCE(SUM(br.required_quantity), 0) AS excess
            FROM projects p
            LEFT JOIN bom_items bi ON bi.project_id = p.id AND bi.deleted_at IS NULL
            LEFT JOIN bom_requirements br ON br.bom_item_id = bi.id
            LEFT JOIN receipt_items ri ON ri.bom_item_id = bi.id
            WHERE p.deleted_at IS NULL $pidFilter
            GROUP BY p.id, p.project_code, p.name
            ORDER BY excess DESC
        ");

        $this->table(
            ['Project ID', 'Code', 'Name', 'BOM Total', 'Raw Received', 'Excess'],
            array_map(fn($r) => [
                $r->project_id,
                $r->project_code,
                $r->project_name,
                $r->bom_total,
                $r->raw_received_total,
                ($r->excess > 0 ? '+' : '') . $r->excess . ($r->excess > 0 ? ' <<OVER' : ''),
            ], $projectTotals)
        );

        $excessProjects = array_filter($projectTotals, fn($r) => $r->excess > 0);

        // ----------------------------------------------------------------
        // PHASE 2: Part-level excess breakdown
        // ----------------------------------------------------------------
        $this->newLine();
        $this->line('--- PHASE 2: Part-Level Excess (per BomItem+Side) ---');

        foreach ($excessProjects as $proj) {
            $pid = (int)$proj->project_id;
            $this->newLine();
            $this->warn("Project [{$proj->project_code}] ID={$pid}  BOM={$proj->bom_total}  Received={$proj->raw_received_total}  Excess=+{$proj->excess}");

            $partDetails = DB::select("
                SELECT
                    bi.id AS bom_item_id,
                    bi.item_no,
                    bi.standard_part_no,
                    bi.jig_no,
                    bi.unit_no,
                    br.side,
                    br.required_quantity,
                    COALESCE(SUM(CASE WHEN ri.status IN (
                        'received','sent_to_qc','qc_received','qc_approved',
                        'qc_rework','qc_inspected','paint_completed',
                        'assembly_completed','returned_to_store'
                    ) THEN ri.received_quantity ELSE 0 END), 0) AS raw_received,
                    COALESCE(SUM(CASE WHEN ri.status IN (
                        'received','sent_to_qc','qc_received','qc_approved',
                        'qc_rework','qc_inspected','paint_completed',
                        'assembly_completed','returned_to_store'
                    ) THEN ri.received_quantity ELSE 0 END), 0) - br.required_quantity AS excess
                FROM bom_requirements br
                JOIN bom_items bi ON bi.id = br.bom_item_id AND bi.deleted_at IS NULL
                LEFT JOIN receipt_items ri ON ri.bom_item_id = br.bom_item_id AND ri.side = br.side
                WHERE bi.project_id = $pid
                GROUP BY bi.id, bi.item_no, bi.standard_part_no, bi.jig_no, bi.unit_no, br.side, br.required_quantity
                HAVING COALESCE(SUM(CASE WHEN ri.status IN (
                    'received','sent_to_qc','qc_received','qc_approved',
                    'qc_rework','qc_inspected','paint_completed',
                    'assembly_completed','returned_to_store'
                ) THEN ri.received_quantity ELSE 0 END), 0) > br.required_quantity
                ORDER BY excess DESC
            ");

            if (!empty($partDetails)) {
                $this->table(
                    ['BomItemID','ItemNo','Part No','Jig','Unit','Side','BOM Req','Received','Excess'],
                    array_map(fn($r) => [
                        $r->bom_item_id, $r->item_no, $r->standard_part_no,
                        $r->jig_no ?? 'N/A', $r->unit_no ?? 'N/A', $r->side,
                        $r->required_quantity, $r->raw_received, '+' . $r->excess,
                    ], $partDetails)
                );
            } else {
                $this->info('  No individual part/side combos over BOM requirement — check orphan receipts below.');
            }

            // ----------------------------------------------------------------
            // PHASE 3: Orphan receipts (received for side with no BOM entry)
            // ----------------------------------------------------------------
            $this->newLine();
            $this->line("  -- PHASE 3: Orphan Receipt Items (no BOM requirement for this side) --");

            $orphans = DB::select("
                SELECT
                    ri.id AS receipt_item_id, r.id AS receipt_id,
                    bi.id AS bom_item_id, bi.standard_part_no, bi.jig_no, bi.unit_no,
                    ri.side, ri.received_quantity, ri.status, ri.created_at,
                    u.name AS received_by, r.delivery_note_number, ri.remarks
                FROM receipt_items ri
                JOIN bom_items bi ON bi.id = ri.bom_item_id AND bi.deleted_at IS NULL
                JOIN receipts r ON r.id = ri.receipt_id
                JOIN users u ON u.id = r.received_by
                LEFT JOIN bom_requirements br ON br.bom_item_id = ri.bom_item_id AND br.side = ri.side
                WHERE bi.project_id = $pid
                  AND br.id IS NULL
                  AND ri.status NOT IN ('reverted','returned_to_vendor','scrapped')
                ORDER BY ri.created_at
            ");

            if (!empty($orphans)) {
                $this->error("  !! ORPHAN RECEIPT ITEMS FOUND -- received for a side with NO BOM requirement !!");
                $this->table(
                    ['RI_ID','R_ID','BomItemID','Part No','Jig','Unit','Side','Qty','Status','Created','User','DN#','Remarks'],
                    array_map(fn($r) => [
                        $r->receipt_item_id, $r->receipt_id, $r->bom_item_id,
                        $r->standard_part_no, $r->jig_no ?? 'N/A', $r->unit_no ?? 'N/A',
                        $r->side, $r->received_quantity, $r->status, $r->created_at,
                        $r->received_by, $r->delivery_note_number ?? 'N/A',
                        substr($r->remarks ?? '', 0, 50),
                    ], $orphans)
                );
            } else {
                $this->info('  OK: No orphan receipt items.');
            }
        }

        // ----------------------------------------------------------------
        // PHASE 4: Duplicate transaction investigation (within 60s window)
        // ----------------------------------------------------------------
        $this->newLine();
        $this->line('--- PHASE 4: Duplicate Transaction Detection (same part+side+qty within 60s) ---');

        $allItems = DB::select("
            SELECT
                ri.id AS receipt_item_id, r.id AS receipt_id,
                ri.bom_item_id, bi.standard_part_no, bi.jig_no, bi.unit_no,
                ri.side, ri.received_quantity, ri.status, ri.created_at,
                u.name AS received_by, r.delivery_note_number
            FROM receipt_items ri
            JOIN bom_items bi ON bi.id = ri.bom_item_id AND bi.deleted_at IS NULL
            JOIN receipts r ON r.id = ri.receipt_id
            JOIN users u ON u.id = r.received_by
            WHERE ri.status NOT IN ('reverted','returned_to_vendor','scrapped')
            $pidFilterBi
            ORDER BY ri.bom_item_id, ri.side, ri.created_at
        ");

        // Manual duplicate detection: same bom_item_id+side+qty within 60s
        $dupGroups = [];
        foreach ($allItems as $item) {
            $key = "{$item->bom_item_id}_{$item->side}_{$item->received_quantity}";
            $dupGroups[$key][] = $item;
        }
        $foundDups = false;
        foreach ($dupGroups as $key => $group) {
            if (count($group) < 2) continue;
            $times = array_map(fn($i) => strtotime($i->created_at), $group);
            sort($times);
            for ($i = 0; $i < count($times) - 1; $i++) {
                if ($times[$i + 1] - $times[$i] <= 60) {
                    if (!$foundDups) {
                        $this->error('!! DUPLICATE TRANSACTIONS DETECTED (same qty, same part+side, within 60 seconds) !!');
                        $this->table(
                            ['RI_ID','R_ID','BomItemID','Part No','Jig','Unit','Side','Qty','Status','Created','User','DN#'],
                            array_map(fn($r) => [
                                $r->receipt_item_id, $r->receipt_id, $r->bom_item_id,
                                $r->standard_part_no, $r->jig_no ?? 'N/A', $r->unit_no ?? 'N/A',
                                $r->side, $r->received_quantity, $r->status, $r->created_at,
                                $r->received_by, $r->delivery_note_number ?? 'N/A',
                            ], $group)
                        );
                        $foundDups = true;
                    }
                    break;
                }
            }
        }
        if (!$foundDups) {
            $this->info('OK: No duplicate transactions within 60-second windows.');
        }

        // ----------------------------------------------------------------
        // PHASE 5: Full transaction audit with running total per part+side
        // ----------------------------------------------------------------
        $this->newLine();
        $this->line('--- PHASE 5: Full Transaction History With Running Totals (excess projects only) ---');

        foreach ($excessProjects as $proj) {
            $pid = (int)$proj->project_id;
            $this->newLine();
            $this->warn("Project [{$proj->project_code}] — Transaction History:");

            $txHistory = DB::select("
                SELECT
                    ri.id AS receipt_item_id, r.id AS receipt_id,
                    bi.id AS bom_item_id, bi.standard_part_no, bi.jig_no, bi.unit_no,
                    ri.side, br.required_quantity AS bom_required, ri.received_quantity,
                    ri.status, ri.created_at AS received_at,
                    u.name AS received_by, r.delivery_note_number, ri.remarks
                FROM receipt_items ri
                JOIN receipts r ON r.id = ri.receipt_id
                JOIN bom_items bi ON bi.id = ri.bom_item_id AND bi.deleted_at IS NULL
                JOIN users u ON u.id = r.received_by
                LEFT JOIN bom_requirements br ON br.bom_item_id = ri.bom_item_id AND br.side = ri.side
                WHERE bi.project_id = $pid
                  AND ri.status NOT IN ('reverted','returned_to_vendor','scrapped')
                ORDER BY ri.bom_item_id, ri.side, ri.created_at
            ");

            // Calculate running totals manually
            $runningTotals = [];
            $overReceived = [];
            foreach ($txHistory as $tx) {
                $key = "{$tx->bom_item_id}_{$tx->side}";
                $runningTotals[$key] = ($runningTotals[$key] ?? 0) + $tx->received_quantity;
                $tx->running_total = $runningTotals[$key];
                if ($tx->bom_required !== null && $runningTotals[$key] > $tx->bom_required) {
                    $overReceived[] = $tx;
                }
            }

            if (!empty($overReceived)) {
                $this->error('  !! Transactions that pushed running total ABOVE BOM requirement:');
                $this->table(
                    ['RI_ID','R_ID','BomItem','Part No','Jig','Unit','Side','BOM Req','This Qty','Running Total','Status','Received At','User','DN#','Remarks'],
                    array_map(fn($r) => [
                        $r->receipt_item_id, $r->receipt_id, $r->bom_item_id,
                        $r->standard_part_no, $r->jig_no ?? 'N/A', $r->unit_no ?? 'N/A',
                        $r->side, $r->bom_required, $r->received_quantity,
                        $r->running_total . ' <<OVER',
                        $r->status, $r->received_at, $r->received_by,
                        $r->delivery_note_number ?? 'N/A',
                        substr($r->remarks ?? '', 0, 40),
                    ], $overReceived)
                );
            } else {
                $this->info('  OK: No individual transactions exceeded BOM — excess is likely orphan receipts or side mismatch.');
            }
        }

        // ----------------------------------------------------------------
        // PHASE 6: Workflow Events — store_received audit
        // ----------------------------------------------------------------
        $this->newLine();
        $this->line('--- PHASE 6: All store_received Workflow Events (excess projects) ---');

        foreach ($excessProjects as $proj) {
            $pid = (int)$proj->project_id;

            $events = DB::select("
                SELECT we.id, we.bom_item_id, bi.standard_part_no, bi.jig_no, bi.unit_no,
                       we.side, we.quantity, we.event_type, we.previous_state, we.new_state,
                       we.created_at, u.name AS user_name, we.remarks
                FROM workflow_events we
                JOIN bom_items bi ON bi.id = we.bom_item_id AND bi.deleted_at IS NULL
                JOIN users u ON u.id = we.user_id
                WHERE we.project_id = $pid AND we.event_type = 'store_received'
                ORDER BY we.bom_item_id, we.side, we.created_at
            ");

            $this->newLine();
            $this->warn("[{$proj->project_code}] store_received workflow events: " . count($events));
            if (!empty($events)) {
                $this->table(
                    ['EvtID','BomItem','Part No','Jig','Unit','Side','Qty','Type','Prev','New','Created','User','Remarks'],
                    array_map(fn($r) => [
                        $r->id, $r->bom_item_id, $r->standard_part_no,
                        $r->jig_no ?? 'N/A', $r->unit_no ?? 'N/A',
                        $r->side, $r->quantity, $r->event_type,
                        $r->previous_state, $r->new_state, $r->created_at,
                        $r->user_name, substr($r->remarks ?? '', 0, 50),
                    ], $events)
                );
            }
        }

        // ----------------------------------------------------------------
        // PHASE 7: SUMMARY
        // ----------------------------------------------------------------
        $this->newLine();
        $this->line('======================================================================');
        $this->line('FORENSIC AUDIT COMPLETE — SUMMARY');
        $this->line('======================================================================');

        $totalExcess = array_sum(array_map(fn($r) => max(0, (int)$r->excess), $projectTotals));
        if ($totalExcess === 0) {
            $this->info('CLEAN: Zero excess parts. BOM and receipts are balanced.');
        } else {
            $this->error("TOTAL EXCESS: +{$totalExcess} parts above BOM requirement.");
            $this->line('');
            $this->line('Root Cause Classification:');
            $this->line('  A) ORPHAN RECEIPTS   — receipt_items with no matching bom_requirements side');
            $this->line('  B) DUPLICATE TX      — same qty/part/side submitted twice within 60s');
            $this->line('  C) OVER-DELIVERY     — supplier sent more than BOM required (legitimate)');
            $this->line('  D) SIDE MISMATCH     — wrong side selected when receiving');
        }

        return Command::SUCCESS;
    }
}
