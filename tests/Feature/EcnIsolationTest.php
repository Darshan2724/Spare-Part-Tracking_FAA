<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Models\EcnWorkflowRecord;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\User;
use App\Services\EcnWorkflowService;
use App\Services\QuantityCalculationService;
use App\Services\EcnQuantityCalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EcnIsolationTest extends TestCase
{
    use DatabaseTransactions;

    protected function getAdminUser(): User
    {
        $user = User::where('email', 'admin@sparetrack.internal')->first();
        if (!$user) {
            $user = User::first();
        }
        if (!$user) {
            $user = User::create([
                'name' => 'Admin User',
                'email' => 'admin@sparetrack.internal',
                'password' => bcrypt('password'),
            ]);
            $user->assignRole('ADMIN');
        }
        return $user;
    }

    public function test_ecn_and_regular_bom_quantities_remain_strictly_isolated()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $code = 'ISO-' . uniqid();
        $project = Project::create([
            'project_code' => $code,
            'name' => "Isolation Test Project {$code}",
            'status' => 'active',
        ]);

        // 1. Regular BOM Item: Required = 10 pcs
        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'PART-100',
            'item_no' => 'P100',
            'jig_no' => 'JIG-A',
            'unit_no' => '01',
        ]);

        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'required_quantity' => 10,
        ]);

        // Regular Store intake: 4 pcs
        $receipt = Receipt::create([
            'project_id' => $project->id,
            'receipt_number' => 'REC-' . uniqid(),
            'received_by' => $user->id,
            'received_at' => now(),
        ]);
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'received_quantity' => 4,
            'status' => 'received',
        ]);

        // 2. ECN Item for the same part number and project: Required = 25 pcs
        $ecnReq = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-99',
            'jig_no' => 'JIG-A',
            'unit_no' => '01',
            'part_no' => 'PART-100',
            'side' => 'LA',
            'side_display' => 'LH',
            'side_family' => 'LEFT',
            'required_qty' => 25,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);

        $qtyService = new QuantityCalculationService();
        $ecnQtyService = new EcnQuantityCalculationService();

        // Calculate regular metrics
        $regularMetrics = $qtyService->calculateProjectMetrics($project);
        $this->assertEquals(10, $regularMetrics['total_parts'], 'Regular total parts must be 10 (not contaminated by 25 ECN)');
        $this->assertEquals(4, $regularMetrics['total_parts_received'], 'Regular received must be 4');
        $this->assertEquals(6, $regularMetrics['parts_pending'], 'Regular pending must be 6');

        // Calculate ECN metrics
        $ecnSummary = $ecnQtyService->calculateEcnDashboardSummary(['project_id' => $project->id]);
        $this->assertEquals(25, $ecnSummary['total_parts'], 'ECN total parts must be 25 (not contaminated by 10 regular)');
        $this->assertEquals(0, $ecnSummary['total_received'], 'ECN received must be 0');
        $this->assertEquals(25, $ecnSummary['parts_pending'], 'ECN pending must be 25');

        // 3. Receive ECN part: 15 pcs
        $wfService = new EcnWorkflowService();
        $wfService->receiveStore($ecnReq->id, 15, 'ECN delivery', $user->id);

        // Verify Regular metrics did NOT change
        $regularMetricsAfter = $qtyService->calculateProjectMetrics($project);
        $this->assertEquals(10, $regularMetricsAfter['total_parts']);
        $this->assertEquals(4, $regularMetricsAfter['total_parts_received'], 'Regular received must stay 4');
        $this->assertEquals(6, $regularMetricsAfter['parts_pending'], 'Regular pending must stay 6');

        // Verify ECN metrics updated independently
        $ecnSummaryAfter = $ecnQtyService->calculateEcnDashboardSummary(['project_id' => $project->id]);
        $this->assertEquals(25, $ecnSummaryAfter['total_parts']);
        $this->assertEquals(15, $ecnSummaryAfter['total_received']);
        $this->assertEquals(10, $ecnSummaryAfter['parts_pending']);
    }

    public function test_regular_and_ecn_drilldown_isolation()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $code = 'DRILL-' . uniqid();
        $project = Project::create([
            'project_code' => $code,
            'name' => "Drilldown Isolation Project {$code}",
            'status' => 'active',
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'REG-PART-01',
            'item_no' => 'R01',
            'jig_no' => 'JIG-R',
            'unit_no' => '01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'required_quantity' => 8,
        ]);

        $ecnReq = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-DRILL',
            'jig_no' => 'JIG-E',
            'unit_no' => '02',
            'part_no' => 'ECN-PART-99',
            'side' => 'LA',
            'side_display' => 'LH',
            'side_family' => 'LEFT',
            'required_qty' => 12,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);

        // 1. Query Regular Total Parts drilldown
        $resReg = $this->getJson("/api/v1/dashboard/kpi-drilldown?kpi=total_parts&project_id={$project->id}");
        $resReg->assertStatus(200);
        $regData = $resReg->json('data');
        $partNos = array_column($regData, 'part_no');

        $this->assertContains('REG-PART-01', $partNos);
        $this->assertNotContains('ECN-PART-99', $partNos, 'ECN parts must NOT appear in regular total parts drilldown');

        // 2. Query ECN drilldown
        $resEcn = $this->getJson("/api/v1/dashboard/kpi-drilldown?kpi=ecn&project_id={$project->id}");
        $resEcn->assertStatus(200);
        $ecnData = $resEcn->json('data');
        $ecnPartNos = array_column($ecnData, 'part_no');

        $this->assertContains('ECN-PART-99', $ecnPartNos);
        $this->assertNotContains('REG-PART-01', $ecnPartNos, 'Regular BOM parts must NOT appear in ECN drilldown');
    }
}
