<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AllTypesJigExportNamingTest extends TestCase
{
    protected User $adminUser;
    protected Project $project;
    protected BomItem $mfgItem;
    protected BomItem $bopItem;
    protected BomItem $stdItem;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        $this->adminUser = User::firstOrCreate(
            ['email' => 'admin_jig_naming@sparetrack.internal'],
            ['name' => 'Admin Jig Naming', 'password' => bcrypt('password')]
        );
        $this->adminUser->assignRole($role);

        $this->project = Project::create([
            'project_code' => 'TEST-JIGNAMES-' . uniqid(),
            'name' => 'Jig Export Naming Test Project',
            'status' => 'active',
        ]);

        // Create MFG Jig
        $this->mfgItem = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'MFG-PART-101',
            'item_no' => '1',
            'jig_no' => 'FIXTURE-ALPHA',
            'unit_no' => 'Unit 01',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->mfgItem->id,
            'side' => 'RH',
            'required_quantity' => 10,
        ]);

        // Create BOP Jig
        $this->bopItem = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'BOP-PART-202',
            'item_no' => '2',
            'jig_no' => 'FIXTURE-BETA',
            'unit_no' => 'Unit 01',
            'part_type' => 'BOP',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->bopItem->id,
            'side' => 'COMMON',
            'required_quantity' => 5,
        ]);

        // Create STD Jig
        $this->stdItem = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'STD-PART-303',
            'item_no' => '3',
            'jig_no' => 'FIXTURE-GAMMA',
            'unit_no' => 'Unit 01',
            'part_type' => 'STD',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->stdItem->id,
            'side' => 'LH',
            'required_quantity' => 8,
        ]);
    }

    public function test_all_types_jig_export_attaches_short_bom_type_suffix(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $response = $this->getJson("/api/v1/export/project-jigs?project_id={$this->project->id}");
        $response->assertStatus(200);

        $tempFile = tempnam(sys_get_temp_dir(), 'jig_alltypes_') . '.xlsx';
        file_put_contents($tempFile, $response->streamedContent());

        try {
            $spreadsheet = IOFactory::load($tempFile);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();

            $foundMfgBanner = false;
            $foundBopBanner = false;
            $foundStdBanner = false;

            $foundMfgFix = false;
            $foundBopFix = false;
            $foundStdFix = false;

            for ($r = 1; $r <= $highestRow; $r++) {
                $valA = (string)$sheet->getCell("A{$r}")->getValue();

                if ($valA === 'FIXTURE-ALPHA (MFG)') {
                    $foundMfgBanner = true;
                } elseif ($valA === 'FIXTURE-BETA (BOP)') {
                    $foundBopBanner = true;
                } elseif ($valA === 'FIXTURE-GAMMA (STD)') {
                    $foundStdBanner = true;
                }

                if ($valA === 'FIXTURE-ALPHA-RH (MFG)') {
                    $foundMfgFix = true;
                } elseif ($valA === 'FIXTURE-BETA (BOP)') {
                    $foundBopFix = true;
                } elseif ($valA === 'FIXTURE-GAMMA-LH (STD)') {
                    $foundStdFix = true;
                }
            }

            $this->assertTrue($foundMfgBanner, 'MFG Jig banner must be labeled FIXTURE-ALPHA (MFG)');
            $this->assertTrue($foundBopBanner, 'BOP Jig banner must be labeled FIXTURE-BETA (BOP)');
            $this->assertTrue($foundStdBanner, 'STD Jig banner must be labeled FIXTURE-GAMMA (STD)');

            $this->assertTrue($foundMfgFix, 'MFG Fix No. row must be labeled FIXTURE-ALPHA-RH (MFG)');
            $this->assertTrue($foundBopFix, 'BOP Fix No. row must be labeled FIXTURE-BETA (BOP)');
            $this->assertTrue($foundStdFix, 'STD Fix No. row must be labeled FIXTURE-GAMMA-LH (STD)');

            // Database Jig names must remain untouched
            $this->assertEquals('FIXTURE-ALPHA', $this->mfgItem->fresh()->jig_no);
            $this->assertEquals('FIXTURE-BETA', $this->bopItem->fresh()->jig_no);
            $this->assertEquals('FIXTURE-GAMMA', $this->stdItem->fresh()->jig_no);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function test_type_filtered_export_does_not_duplicate_suffix(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $response = $this->getJson("/api/v1/export/project-jigs?project_id={$this->project->id}&part_type=MFG");
        $response->assertStatus(200);

        $tempFile = tempnam(sys_get_temp_dir(), 'jig_filtered_') . '.xlsx';
        file_put_contents($tempFile, $response->streamedContent());

        try {
            $spreadsheet = IOFactory::load($tempFile);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();

            for ($r = 1; $r <= $highestRow; $r++) {
                $valA = (string)$sheet->getCell("A{$r}")->getValue();
                // When explicitly filtered to MFG, type is not appended or duplicated
                $this->assertStringNotContainsString('(MFG) (MFG)', $valA);
            }
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
