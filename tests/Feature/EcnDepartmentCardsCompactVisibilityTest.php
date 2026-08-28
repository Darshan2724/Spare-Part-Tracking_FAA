<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\Department;
use App\Models\EcnImportBatch;
use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Models\EcnWorkflowRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\EcnQuantityCalculationService;
use App\Services\HierarchyService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EcnDepartmentCardsCompactVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected Project $project;
    protected Department $storeDept;
    protected Department $qcDept;
    protected Department $reworkDept;
    protected Department $paintDept;
    protected Department $assemblyDept;
    protected EcnImportBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        $this->storeDept = Department::firstOrCreate(['code' => 'STORE'], ['name' => 'Store']);
        $this->qcDept = Department::firstOrCreate(['code' => 'QC'], ['name' => 'QC']);
        $this->reworkDept = Department::firstOrCreate(['code' => 'REWORK'], ['name' => 'Rework']);
        $this->paintDept = Department::firstOrCreate(['code' => 'PAINT'], ['name' => 'Paint']);
        $this->assemblyDept = Department::firstOrCreate(['code' => 'ASSEMBLY'], ['name' => 'Assembly']);

        $this->user = User::firstOrCreate(
            ['email' => 'admin_ecn_cards_test@faithautomation.com'],
            [
                'name' => 'Admin ECN Card Tester',
                'password' => bcrypt('password'),
                'role' => 'ADMIN',
                'department_id' => $this->storeDept->id,
                'is_active' => true,
            ]
        );
        $this->user->syncRoles(['ADMIN']);

        $this->project = Project::create([
            'project_code' => 'PRJ-CARD-' . uniqid(),
            'name' => 'ECN Card Test Project',
            'status' => 'active',
        ]);

        $this->batch = EcnImportBatch::create([
            'project_id' => $this->project->id,
            'filename' => 'ecn_card_test.xlsx',
            'imported_by' => $this->user->id,
            'status' => 'completed',
        ]);
    }

    public function test_format_ecn_summary_display_formats_generic_ecn_parts_correctly(): void
    {
        $singleSummary = [
            ['ecn_number' => 'ECN-1', 'part_count' => 1],
        ];
        $this->assertEquals('ECN (1 part)', EcnQuantityCalculationService::formatEcnSummaryDisplay($singleSummary));

        $pluralSummary = [
            ['ecn_number' => 'ECN-1', 'part_count' => 4],
            ['ecn_number' => 'ECN-3', 'part_count' => 2],
        ];
        $this->assertEquals('ECN (6 parts)', EcnQuantityCalculationService::formatEcnSummaryDisplay($pluralSummary));

        // Detailed display preserves individual numbers
        $this->assertEquals('ECN-1 • 4 parts, ECN-3 • 2 parts', EcnQuantityCalculationService::formatEcnDetailedDisplay($pluralSummary));

        $emptySummary = [];
        $this->assertNull(EcnQuantityCalculationService::formatEcnSummaryDisplay($emptySummary));
    }

    public function test_hierarchy_service_attaches_compact_ecn_display_to_cards(): void
    {
        // Create base BOM item
        $bomItem = BomItem::create([
            'project_id' => $this->project->id,
            'item_no' => '1',
            'standard_part_no' => 'STD-CARD-001',
            'part_name' => 'Base Pin',
            'jig_no' => 'JIG-01',
            'unit_no' => '01',
            'qty_lh' => 2,
            'qty_rh' => 2,
            'total_qty' => 4,
        ]);

        // Create ECN requirement 1 in Store
        $ecnReq1 = EcnRequirement::create([
            'ecn_import_batch_id' => $this->batch->id,
            'project_id' => $this->project->id,
            'ecn_number' => 'ECN-101',
            'item_no' => 'E1',
            'part_no' => 'ECN-PART-01',
            'description' => 'Bracket ECN',
            'jig_no' => 'JIG-01',
            'unit_no' => '01',
            'side' => 'LH',
            'side_display' => 'LH',
            'required_qty' => 4,
            'received_qty' => 4,
            'current_state' => 'STORE',
            'action_type' => 'ADD',
        ]);

        $hierarchyService = app(HierarchyService::class);

        // 1. In Store Department Hierarchy
        $storeHierarchy = $hierarchyService->getDepartmentHierarchy('store', $this->project->id);
        $this->assertTrue($storeHierarchy['is_hierarchical']);
        $this->assertNotEmpty($storeHierarchy['jigs']);

        $storeJig = $storeHierarchy['jigs'][0];
        $this->assertEquals('JIG-01', $storeJig['jig_name']);
        $this->assertTrue($storeJig['is_ecn_present']);
        $this->assertEquals(4, $storeJig['ecn_part_count']);
        $this->assertEquals(4, $storeJig['ecn_parts']);
        $this->assertEquals('ECN (4 parts)', $storeJig['ecn_number_display']);

        $storeUnit = $storeJig['units'][0];
        $this->assertTrue($storeUnit['is_ecn_present']);
        $this->assertEquals(4, $storeUnit['ecn_part_count']);
        $this->assertEquals('ECN (4 parts)', $storeUnit['ecn_number_display']);

        // 2. In QC Department Hierarchy (should be 0 because item is in STORE, not QC)
        $qcHierarchy = $hierarchyService->getDepartmentHierarchy('qc', $this->project->id);
        $qcJig = $qcHierarchy['jigs'][0];
        $this->assertFalse($qcJig['is_ecn_present']);
        $this->assertEquals(0, $qcJig['ecn_part_count']);
        $this->assertEquals(0, $qcJig['ecn_parts']);
        $this->assertNull($qcJig['ecn_number_display']);

        // Move ECN requirement to QC
        $ecnReq1->current_state = 'QC';
        $ecnReq1->save();

        // 3. Now QC should report the ECN card badge
        $qcHierarchyAfter = $hierarchyService->getDepartmentHierarchy('qc', $this->project->id);
        $qcJigAfter = $qcHierarchyAfter['jigs'][0];
        $this->assertTrue($qcJigAfter['is_ecn_present']);
        $this->assertEquals(4, $qcJigAfter['ecn_part_count']);
        $this->assertEquals('ECN (4 parts)', $qcJigAfter['ecn_number_display']);

        // And Store should no longer show it in active stock
        $storeHierarchyAfter = $hierarchyService->getDepartmentHierarchy('store', $this->project->id);
        $storeJigAfter = $storeHierarchyAfter['jigs'][0];
        $this->assertFalse($storeJigAfter['is_ecn_present']);
    }

    public function test_api_store_and_qc_hierarchy_endpoints_return_structured_ecn_card_fields(): void
    {
        // Create base BOM item
        BomItem::create([
            'project_id' => $this->project->id,
            'item_no' => '1',
            'standard_part_no' => 'STD-CARD-002',
            'part_name' => 'Base Flange',
            'jig_no' => 'JIG-02',
            'unit_no' => '02',
            'qty_lh' => 1,
            'qty_rh' => 1,
            'total_qty' => 2,
        ]);

        EcnRequirement::create([
            'ecn_import_batch_id' => $this->batch->id,
            'project_id' => $this->project->id,
            'ecn_number' => 'ECN-101',
            'item_no' => 'E2',
            'part_no' => 'ECN-PART-02',
            'description' => 'Cover ECN',
            'jig_no' => 'JIG-02',
            'unit_no' => '02',
            'side' => 'LH',
            'side_display' => 'LH',
            'required_qty' => 2,
            'received_qty' => 2,
            'current_state' => 'STORE',
            'action_type' => 'ADD',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/store/hierarchy?project_id={$this->project->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('is_hierarchical', true);
        $response->assertJsonPath('jigs.0.ecn_number_display', 'ECN (2 parts)');
        $response->assertJsonPath('jigs.0.is_ecn_present', true);
        $response->assertJsonPath('jigs.0.ecn_parts', 2);
        $response->assertJsonPath('jigs.0.ecn_part_count', 2);
        $response->assertJsonPath('jigs.0.units.0.ecn_number_display', 'ECN (2 parts)');
    }
}
