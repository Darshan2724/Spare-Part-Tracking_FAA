<?php

namespace App\Console\Commands;

use App\Models\BomItem;
use App\Models\Project;
use App\Models\PaintRecord;
use App\Models\QcInspection;
use App\Models\ReceiptItem;
use App\Models\AssemblyRecord;
use App\Services\HierarchyService;
use Illuminate\Console\Command;

class InspectMfgState extends Command
{
    protected $signature = 'inspect:mfg-state {--project=FA-273} {--part=08} {--jig=LIMORD70} {--unit=04}';
    protected $description = 'Inspect canonical MFG parts and their hierarchy representation';

    public function handle(HierarchyService $hierarchyService): int
    {
        $projectCode = $this->option('project') ?: 'FA-273';
        $project = Project::where('project_code', $projectCode)->orWhere('id', (int)$projectCode)->first();
        if (!$project) {
            $this->error("Project not found: {$projectCode}");
            return 1;
        }

        $projects = Project::whereIn('project_code', ['FA-273', 'FA-279'])->get();
        foreach ($projects as $project) {
            $this->newLine();
            $this->info("==========================================");
            $this->info("Project: {$project->name} ({$project->project_code}) ID: {$project->id}");
            $this->info("==========================================");

            $bomItems = BomItem::where('project_id', $project->id)->orderBy('jig_no')->orderBy('unit_no')->orderBy('part_type')->get();
            $this->info("Total BOM Items: " . $bomItems->count());

            $byJig = $bomItems->groupBy(fn($i) => strtoupper(trim($i->jig_no ?: 'GENERAL')));
            foreach ($byJig as $jigName => $jigItems) {
                $this->info("  Jig: {$jigName} (Items: " . $jigItems->count() . ")");
                $byUnit = $jigItems->groupBy(fn($i) => trim($i->unit_no ?: '00'));
                foreach ($byUnit as $unitRaw => $uItems) {
                    $mfgC = $uItems->where('part_type', 'MFG')->count();
                    $bopC = $uItems->where('part_type', 'BOP')->count();
                    $stdC = $uItems->where('part_type', 'STD')->count();
                    $this->line("    Raw Unit: '{$unitRaw}' -> Total: {$uItems->count()} (MFG: {$mfgC}, BOP: {$bopC}, STD: {$stdC})");
                }
            }
        }

        // 3. Find all BOM items by type and raw unit_no
        $fa273 = Project::where('project_code', 'FA-273')->first();
        $this->newLine();
        $this->info("Distinct raw unit_no in project FA-273 by BOM type:");
        $types = ['MFG', 'BOP', 'STD'];
        foreach ($types as $t) {
            $distinctUnits = BomItem::where('project_id', $fa273->id)->where('part_type', $t)
                ->select('jig_no', 'unit_no')
                ->distinct()
                ->orderBy('jig_no')
                ->orderBy('unit_no')
                ->get();
            $this->line("  BOM Type {$t}: " . $distinctUnits->map(fn($u) => "{$u->jig_no}:'{$u->unit_no}'")->implode(', '));
        }

        // Details for LIMORD70 and LIMOFD20
        $this->newLine();
        $this->info("All BOM items in LIMORD70:");
        $limord70 = BomItem::where('project_id', $fa273->id)->where('jig_no', 'LIMORD70')->with(['requirements'])->get();
        foreach ($limord70 as $it) {
            $reqSides = $it->requirements->map(fn($r) => "{$r->side}:{$r->required_quantity}")->implode(', ');
            $this->line("  ID: {$it->id} | Part: {$it->standard_part_no} | Unit: '{$it->unit_no}' | Type: {$it->part_type} | Req: [{$reqSides}]");
        }

        $this->newLine();
        $this->info("All BOM items in LIMOFD20:");
        $limofd20 = BomItem::where('project_id', $fa273->id)->where('jig_no', 'LIMOFD20')->with(['requirements'])->get();
        foreach ($limofd20 as $it) {
            $reqSides = $it->requirements->map(fn($r) => "{$r->side}:{$r->required_quantity}")->implode(', ');
            $this->line("  ID: {$it->id} | Part: {$it->standard_part_no} | Unit: '{$it->unit_no}' | Type: {$it->part_type} | Req: [{$reqSides}]");
        }

        // 4. Run HierarchyService
        $this->info("\n--- Running HierarchyService::getDepartmentHierarchy('manager', {$fa273->id}) ---");
        $hierarchy = $hierarchyService->getDepartmentHierarchy('manager', $fa273->id, []);

        $this->info("Hierarchy total jigs: " . count($hierarchy['jigs'] ?? []));
        foreach ($hierarchy['jigs'] ?? [] as $jig) {
            $this->info("Jig: {$jig['jig_name']} (Type: {$jig['jig_type']}) - Units: " . count($jig['units']));
            foreach ($jig['units'] as $unit) {
                $hasCommon = !empty($unit['has_common']);
                $comParts = count($unit['sides']['COMMON']['parts'] ?? []);
                $lhParts = count($unit['sides']['LH']['parts'] ?? []);
                $rhParts = count($unit['sides']['RH']['parts'] ?? []);
                $rootParts = count($unit['parts'] ?? []);
                $this->line("    Unit: '{$unit['unit_no']}' (Common: " . ($hasCommon ? 'YES' : 'NO') . ") -> Parts: root={$rootParts}, Common={$comParts}, LH={$lhParts}, RH={$rhParts}");

                // Breakdown of parts by part_type
                $allParts = [];
                if ($hasCommon) {
                    $allParts = $unit['sides']['COMMON']['parts'] ?? [];
                } else {
                    $allParts = array_merge($unit['sides']['LH']['parts'] ?? [], $unit['sides']['RH']['parts'] ?? []);
                }
                $mfgCount = 0; $bopCount = 0; $stdCount = 0;
                foreach ($allParts as $p) {
                    $pt = strtoupper($p['part_type'] ?? 'UNKNOWN');
                    if ($pt === 'MFG') $mfgCount++;
                    elseif ($pt === 'BOP') $bopCount++;
                    elseif ($pt === 'STD') $stdCount++;
                }
                $this->line("      -> MFG: {$mfgCount}, BOP: {$bopCount}, STD: {$stdCount}");

                if ($jig['jig_name'] === 'LIMORD70') {
                    foreach ($allParts as $p) {
                        $this->line("         Part: {$p['standard_part_no']} | Type: {$p['part_type']} | Side: {$p['side']} | Req: {$p['required_qty']} | Rec: {$p['received_qty']} | Status: {$p['status_badge']}");
                    }
                }
            }
        }

        return 0;
    }
}
