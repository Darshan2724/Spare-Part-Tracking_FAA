<?php

namespace Database\Seeders;

use App\Models\AssemblyRecord;
use App\Models\BomImportBatch;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Department;
use App\Models\EcnImportBatch;
use App\Models\EcnReceiptItem;
use App\Models\EcnRequirement;
use App\Models\EcnWorkflowRecord;
use App\Models\PaintRecord;
use App\Models\Project;
use App\Models\PurchaseQueueItem;
use App\Models\QcInspection;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\ReworkRecord;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ScaleTestDatasetSeeder extends Seeder
{
    /**
     * Target specification:
     * Total Projects: 8
     * Total Parts: 15,343
     * Uneven distribution across Store, QC, Rework, Assembly:
     *   Store:    5,420
     *   QC:       4,115
     *   Rework:   1,673
     *   Assembly: 4,135
     *   Total:    15,343 (5420 + 4115 + 1673 + 4135 = 15343)
     */
    public const TARGET_TOTAL_PARTS = 15343;
    public const TARGET_STORE = 5420;
    public const TARGET_QC = 4115;
    public const TARGET_REWORK = 1673;
    public const TARGET_ASSEMBLY = 4135;

    public function run(): void
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(600);

        $now = now();

        // 1. Ensure required Users & Suppliers exist
        $admin = User::firstOrCreate(
            ['email' => 'admin@sparetrack.internal'],
            ['name' => 'System Admin', 'password' => bcrypt('password123'), 'is_active' => true]
        );
        $storeUser = User::firstOrCreate(
            ['email' => 'store@sparetrack.internal'],
            ['name' => 'Store Officer', 'password' => bcrypt('password123'), 'is_active' => true]
        );
        $qcUser = User::firstOrCreate(
            ['email' => 'qc@sparetrack.internal'],
            ['name' => 'QC Inspector', 'password' => bcrypt('password123'), 'is_active' => true]
        );
        $reworkUser = User::firstOrCreate(
            ['email' => 'rework@sparetrack.internal'],
            ['name' => 'Rework Specialist', 'password' => bcrypt('password123'), 'is_active' => true]
        );
        $assemblyUser = User::firstOrCreate(
            ['email' => 'assembly@sparetrack.internal'],
            ['name' => 'Assembly Lead', 'password' => bcrypt('password123'), 'is_active' => true]
        );

        $suppliers = Supplier::all();
        if ($suppliers->isEmpty()) {
            $suppliers = collect([
                Supplier::create(['code' => 'SUP-001', 'name' => 'Precision Components Ltd']),
                Supplier::create(['code' => 'SUP-002', 'name' => 'Apex Tooling & Dies']),
                Supplier::create(['code' => 'SUP-003', 'name' => 'Global Automation Spares']),
            ]);
        }
        $supplierIds = $suppliers->pluck('id')->toArray();

        // 2. Project Plan: 8 Projects totaling exactly 15,343 parts
        // Each project breakdown: [code, name, store, qc, rework, assembly, total]
        $projectConfigs = [
            [
                'code' => 'FA-301',
                'name' => 'Body Side Main Fixture',
                'customer' => 'Tata Motors',
                'store' => 865,
                'qc' => 660,
                'rework' => 265,
                'assembly' => 660,
                'total' => 2450,
            ],
            [
                'code' => 'FA-302',
                'name' => 'Underbody Framing Line',
                'customer' => 'Mahindra Auto',
                'store' => 750,
                'qc' => 570,
                'rework' => 230,
                'assembly' => 570,
                'total' => 2120,
            ],
            [
                'code' => 'FA-303',
                'name' => 'Roof Bow Welding Cell',
                'customer' => 'Maruti Suzuki',
                'store' => 825,
                'qc' => 630,
                'rework' => 255,
                'assembly' => 630,
                'total' => 2340,
            ],
            [
                'code' => 'FA-304',
                'name' => 'Door Hemming Station',
                'customer' => 'Hyundai India',
                'store' => 665,
                'qc' => 505,
                'rework' => 205,
                'assembly' => 515,
                'total' => 1890,
            ],
            [
                'code' => 'FA-305',
                'name' => 'Front Header Fixture',
                'customer' => 'Toyota Kirloskar',
                'store' => 710,
                'qc' => 540,
                'rework' => 220,
                'assembly' => 545,
                'total' => 2015,
            ],
            [
                'code' => 'FA-306',
                'name' => 'Rear Floor Robotic Gripper',
                'customer' => 'Kia Motors',
                'store' => 615,
                'qc' => 465,
                'rework' => 190,
                'assembly' => 465,
                'total' => 1735,
            ],
            [
                'code' => 'FA-307',
                'name' => 'Side Member Geo Jig',
                'customer' => 'Ashok Leyland',
                'store' => 555,
                'qc' => 420,
                'rework' => 172,
                'assembly' => 416,
                'total' => 1563,
            ],
            [
                'code' => 'FA-308',
                'name' => 'Cross Rail Clamping Fixture',
                'customer' => 'Force Motors',
                'store' => 435,
                'qc' => 325,
                'rework' => 136,
                'assembly' => 334,
                'total' => 1230,
            ],
        ];

        // Sanity Check sums before proceeding
        $sumStore = array_sum(array_column($projectConfigs, 'store'));
        $sumQc = array_sum(array_column($projectConfigs, 'qc'));
        $sumRework = array_sum(array_column($projectConfigs, 'rework'));
        $sumAssembly = array_sum(array_column($projectConfigs, 'assembly'));
        $sumTotal = array_sum(array_column($projectConfigs, 'total'));

        if ($sumStore !== self::TARGET_STORE || $sumQc !== self::TARGET_QC || $sumRework !== self::TARGET_REWORK || $sumAssembly !== self::TARGET_ASSEMBLY || $sumTotal !== self::TARGET_TOTAL_PARTS) {
            throw new \RuntimeException("Sanity check failed: Store={$sumStore}, QC={$sumQc}, Rework={$sumRework}, Assembly={$sumAssembly}, Total={$sumTotal}");
        }

        // Clean out existing data to ensure absolute exactness
        $this->cleanExistingProjects();

        $jigs = ['JIG-01', 'JIG-02', 'JIG-03', 'JIG-04', 'JIG-05'];
        $units = ['Unit 01', 'Unit 02', 'Unit 03', 'Unit 04', 'Unit 05'];
        $sides = ['LH', 'RH', 'COMMON'];

        foreach ($projectConfigs as $pIndex => $cfg) {
            $project = Project::create([
                'project_code' => $cfg['code'],
                'name' => $cfg['name'],
                'customer_name' => $cfg['customer'],
                'description' => "Scale testing dataset project {$cfg['code']}",
                'status' => 'active',
                'start_date' => $now->copy()->subDays(30),
                'target_completion_date' => $now->copy()->addDays(60),
                'created_by' => $admin->id,
            ]);

            // Create BomImportBatch
            $batch = BomImportBatch::create([
                'project_id' => $project->id,
                'filename' => "{$cfg['code']}_FULL_BOM.xlsx",
                'original_filename' => "{$cfg['code']}_FULL_BOM.xlsx",
                'bom_type' => 'MFG',
                'imported_by' => $admin->id,
                'status' => 'completed',
                'total_rows' => $cfg['total'],
                'successful_rows' => $cfg['total'],
            ]);

            // Create parent Receipt for this project
            $receipt = Receipt::create([
                'project_id' => $project->id,
                'supplier_id' => $supplierIds[$pIndex % count($supplierIds)],
                'received_by' => $storeUser->id,
                'received_date' => $now->copy()->subDays(5)->toDateString(),
                'status' => 'completed',
                'notes' => "Scale dataset intake for {$cfg['code']}",
            ]);

            // Partition parts into stages: Store, QC, Rework, Assembly
            $partsTarget = $cfg['total'];
            $storeCount = $cfg['store'];
            $qcCount = $cfg['qc'];
            $reworkCount = $cfg['rework'];
            $assemblyCount = $cfg['assembly'];

            // We must ensure BOP, STD, and MFG are all present in this project
            // Target part_type breakdown: ~25% BOP, ~25% STD, ~50% MFG
            $bopTarget = (int) round($partsTarget * 0.25);
            $stdTarget = (int) round($partsTarget * 0.25);
            $mfgTarget = $partsTarget - ($bopTarget + $stdTarget);

            // Assign stage to each index from 1 to $partsTarget:
            // Indices 1 .. storeCount: Store
            // Indices storeCount+1 .. storeCount+qcCount: QC
            // Indices storeCount+qcCount+1 .. storeCount+qcCount+reworkCount: Rework
            // Indices storeCount+qcCount+reworkCount+1 .. partsTarget: Assembly
            $qcStart = $storeCount + 1;
            $qcEnd = $storeCount + $qcCount;
            $reworkStart = $qcEnd + 1;
            $reworkEnd = $qcEnd + $reworkCount;
            $assemblyStart = $reworkEnd + 1;
            $assemblyEnd = $partsTarget;

            $bomItemsRows = [];
            $requirementsRows = [];

            for ($i = 1; $i <= $partsTarget; $i++) {
                // Determine part type:
                // To adhere to business logic where BOP doesn't undergo QC/Rework:
                // Place BOP in Store and Assembly.
                // Place STD and MFG across all stages.
                if ($i <= $storeCount) {
                    $stage = 'STORE';
                    // Store can have BOP, STD, or MFG
                    if ($i <= (int)($storeCount * 0.45)) {
                        $partType = 'BOP';
                    } elseif ($i <= (int)($storeCount * 0.70)) {
                        $partType = 'STD';
                    } else {
                        $partType = 'MFG';
                    }
                } elseif ($i <= $qcEnd) {
                    $stage = 'QC';
                    // QC cannot have BOP in this architecture
                    $partType = ($i % 2 === 0) ? 'STD' : 'MFG';
                } elseif ($i <= $reworkEnd) {
                    $stage = 'REWORK';
                    // Rework cannot have BOP
                    $partType = ($i % 3 === 0) ? 'STD' : 'MFG';
                } else {
                    $stage = 'ASSEMBLY';
                    // Assembly can have BOP, STD, or MFG
                    $rel = $i - $assemblyStart;
                    if ($rel < (int)($assemblyCount * 0.35)) {
                        $partType = 'BOP';
                    } elseif ($rel < (int)($assemblyCount * 0.65)) {
                        $partType = 'STD';
                    } else {
                        $partType = 'MFG';
                    }
                }

                $jig = $jigs[($i - 1) % count($jigs)];
                $unit = $units[(int)(($i - 1) / count($jigs)) % count($units)];
                $side = $sides[($i - 1) % count($sides)];
                $partNo = sprintf('%s-%s-%05d', $cfg['code'], $partType, $i);

                $bomItemsRows[] = [
                    'project_id' => $project->id,
                    'import_batch_id' => $batch->id,
                    'supplier_id' => $supplierIds[($i - 1) % count($supplierIds)],
                    'jig_no' => $jig,
                    'unit_no' => $unit,
                    'item_no' => (string) $i,
                    'standard_part_no' => $partNo,
                    'part_type' => $partType,
                    'proj_spec_yn' => 'Y',
                    'remarks' => "{$cfg['code']} {$partType} {$stage}",
                    'created_at' => $now,
                    'updated_at' => $now,
                    '_stage' => $stage,
                    '_side' => $side,
                ];
            }

            // Insert BOM Items in chunks and capture inserted IDs
            $insertedBomItems = [];
            foreach (array_chunk($bomItemsRows, 500) as $chunk) {
                $rawChunk = array_map(function ($item) {
                    unset($item['_stage'], $item['_side']);
                    return $item;
                }, $chunk);

                DB::table('bom_items')->insert($rawChunk);
            }

            // Re-fetch created BOM items in order of standard_part_no
            $createdItems = DB::table('bom_items')
                ->where('project_id', $project->id)
                ->orderBy('id')
                ->get(['id', 'standard_part_no', 'part_type']);

            $reqInserts = [];
            $receiptItemInserts = [];
            $qcInspectionsMap = []; // keyed by index to link rework
            $qcInspectionsToInsert = [];

            foreach ($createdItems as $idx => $createdItem) {
                $meta = $bomItemsRows[$idx];
                $stage = $meta['_stage'];
                $side = $meta['_side'];
                $bomItemId = $createdItem->id;

                // 1 Requirement per part with quantity 1
                $reqInserts[] = [
                    'bom_item_id' => $bomItemId,
                    'side' => $side,
                    'required_quantity' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // Operational records matching the designated stage
                if ($stage === 'STORE') {
                    // Resident in Store
                    $receiptItemInserts[] = [
                        'receipt_id' => $receipt->id,
                        'bom_item_id' => $bomItemId,
                        'side' => $side,
                        'received_quantity' => 1,
                        'status' => 'received',
                        'remarks' => 'Store bay intake',
                        'created_at' => $now,
                        'updated_at' => $now,
                        '_stage' => 'STORE',
                        '_idx' => $idx,
                    ];
                } elseif ($stage === 'QC') {
                    // Resident in QC (dispatched from store, awaiting inspection)
                    $receiptItemInserts[] = [
                        'receipt_id' => $receipt->id,
                        'bom_item_id' => $bomItemId,
                        'side' => $side,
                        'received_quantity' => 1,
                        'status' => 'sent_to_qc',
                        'remarks' => 'Dispatched to QC bay',
                        'created_at' => $now,
                        'updated_at' => $now,
                        '_stage' => 'QC',
                        '_idx' => $idx,
                    ];
                } elseif ($stage === 'REWORK') {
                    // In Rework
                    $receiptItemInserts[] = [
                        'receipt_id' => $receipt->id,
                        'bom_item_id' => $bomItemId,
                        'side' => $side,
                        'received_quantity' => 1,
                        'status' => 'qc_rework',
                        'remarks' => 'Sent to QC then Rework',
                        'created_at' => $now,
                        'updated_at' => $now,
                        '_stage' => 'REWORK',
                        '_idx' => $idx,
                    ];
                } elseif ($stage === 'ASSEMBLY') {
                    // In Assembly
                    if ($createdItem->part_type === 'BOP') {
                        $receiptItemInserts[] = [
                            'receipt_id' => $receipt->id,
                            'bom_item_id' => $bomItemId,
                            'side' => $side,
                            'received_quantity' => 1,
                            'status' => 'in_assembly',
                            'remarks' => 'Direct BOP Assembly intake',
                            'created_at' => $now,
                            'updated_at' => $now,
                            '_stage' => 'ASSEMBLY_BOP',
                            '_idx' => $idx,
                        ];
                    } else {
                        $receiptItemInserts[] = [
                            'receipt_id' => $receipt->id,
                            'bom_item_id' => $bomItemId,
                            'side' => $side,
                            'received_quantity' => 1,
                            'status' => 'qc_approved',
                            'remarks' => 'Approved to Assembly direct',
                            'created_at' => $now,
                            'updated_at' => $now,
                            '_stage' => 'ASSEMBLY_MFG',
                            '_idx' => $idx,
                        ];
                    }
                }
            }

            // Insert Requirements in chunks
            foreach (array_chunk($reqInserts, 1000) as $chunk) {
                DB::table('bom_requirements')->insert($chunk);
            }
            unset($reqInserts);

            // Insert Receipt Items in chunks
            foreach (array_chunk($receiptItemInserts, 500) as $chunk) {
                $rawChunk = array_map(function ($r) {
                    unset($r['_stage'], $r['_idx']);
                    return $r;
                }, $chunk);
                DB::table('receipt_items')->insert($rawChunk);
            }

            // Query back created receipt items for this project to attach QC & Rework
            $createdReceiptItems = DB::table('receipt_items')
                ->where('receipt_id', $receipt->id)
                ->orderBy('id')
                ->get(['id', 'bom_item_id', 'side', 'status']);

            $qcInserts = [];
            $reworkItemsToCreate = [];

            foreach ($createdReceiptItems as $i => $recItem) {
                $meta = $receiptItemInserts[$i];
                $stage = $meta['_stage'];

                if ($stage === 'REWORK') {
                    $qcInserts[] = [
                        'bom_item_id' => $recItem->bom_item_id,
                        'receipt_item_id' => $recItem->id,
                        'side' => $recItem->side,
                        'inspected_quantity' => 1,
                        'approved_quantity' => 0,
                        'rejected_quantity' => 0,
                        'rework_quantity' => 1,
                        'result' => 'rework',
                        'destination' => null,
                        'rework_reason' => 'Dimensional adjustment required',
                        'inspected_by' => $qcUser->id,
                        'inspection_date' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                        '_for_rework' => true,
                    ];
                } elseif ($stage === 'ASSEMBLY_MFG') {
                    $qcInserts[] = [
                        'bom_item_id' => $recItem->bom_item_id,
                        'receipt_item_id' => $recItem->id,
                        'side' => $recItem->side,
                        'inspected_quantity' => 1,
                        'approved_quantity' => 1,
                        'rejected_quantity' => 0,
                        'rework_quantity' => 0,
                        'result' => 'approved',
                        'destination' => 'ASSEMBLY',
                        'rework_reason' => null,
                        'inspected_by' => $qcUser->id,
                        'inspection_date' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                        '_for_rework' => false,
                    ];
                }
            }

            // Insert QC inspections
            if (!empty($qcInserts)) {
                $rawQc = array_map(function ($q) {
                    unset($q['_for_rework']);
                    return $q;
                }, $qcInserts);

                foreach (array_chunk($rawQc, 500) as $chunk) {
                    DB::table('qc_inspections')->insert($chunk);
                }

                // Retrieve inserted rework QC inspections
                $reworkQcRecords = DB::table('qc_inspections')
                    ->whereIn('bom_item_id', array_column($qcInserts, 'bom_item_id'))
                    ->where('result', 'rework')
                    ->get(['id', 'bom_item_id', 'side']);

                $reworkInserts = [];
                foreach ($reworkQcRecords as $qcRec) {
                    $reworkInserts[] = [
                        'qc_inspection_id' => $qcRec->id,
                        'bom_item_id' => $qcRec->bom_item_id,
                        'side' => $qcRec->side,
                        'quantity' => 1,
                        'assigned_to' => $reworkUser->id,
                        'status' => 'in_progress',
                        'started_at' => $now,
                        'rework_description' => 'Precision grinding and de-burring',
                        'cycle_number' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach (array_chunk($reworkInserts, 500) as $chunk) {
                    DB::table('rework_records')->insert($chunk);
                }
            }

            // 3. Create ECN requirements and workflow for this project
            $this->seedEcnForProject($project, $admin, $storeUser, $qcUser, $reworkUser, $assemblyUser);
        }
    }

    /**
     * Seed realistic ECN data across Store, QC, Rework, Assembly, and Pending for each project.
     */
    protected function seedEcnForProject(
        Project $project,
        User $admin,
        User $storeUser,
        User $qcUser,
        User $reworkUser,
        User $assemblyUser
    ): void {
        $now = now();

        $ecnBatch = EcnImportBatch::create([
            'project_id' => $project->id,
            'filename' => "{$project->project_code}_ECN_MASTER.xlsx",
            'original_filename' => "{$project->project_code}_ECN_MASTER.xlsx",
            'imported_by' => $admin->id,
            'status' => 'completed',
            'total_rows' => 5,
            'successful_rows' => 5,
            'ecn_numbers' => ["{$project->project_code}-ECN-01", "{$project->project_code}-ECN-02"],
        ]);

        $ecnConfigs = [
            [
                'ecn' => "{$project->project_code}-ECN-01",
                'jig' => 'JIG-01',
                'unit' => 'Unit 01',
                'part' => 'ECN-P01',
                'side' => 'LH',
                'req' => 10,
                'rec' => 10,
                'state' => 'STORE',
                'rec_status' => 'received',
            ],
            [
                'ecn' => "{$project->project_code}-ECN-01",
                'jig' => 'JIG-02',
                'unit' => 'Unit 02',
                'part' => 'ECN-P02',
                'side' => 'RH',
                'req' => 8,
                'rec' => 8,
                'state' => 'QC',
                'rec_status' => 'sent_to_qc',
            ],
            [
                'ecn' => "{$project->project_code}-ECN-02",
                'jig' => 'JIG-03',
                'unit' => 'Unit 03',
                'part' => 'ECN-P03',
                'side' => 'COMMON',
                'req' => 6,
                'rec' => 6,
                'state' => 'REWORK',
                'rec_status' => 'sent_to_qc',
            ],
            [
                'ecn' => "{$project->project_code}-ECN-02",
                'jig' => 'JIG-04',
                'unit' => 'Unit 04',
                'part' => 'ECN-P04',
                'side' => 'LH',
                'req' => 12,
                'rec' => 12,
                'state' => 'ASSEMBLY',
                'rec_status' => 'sent_to_qc',
            ],
            [
                'ecn' => "{$project->project_code}-ECN-02",
                'jig' => 'JIG-05',
                'unit' => 'Unit 05',
                'part' => 'ECN-P05',
                'side' => 'RH',
                'req' => 5,
                'rec' => 0,
                'state' => 'PENDING',
                'rec_status' => null,
            ],
        ];

        foreach ($ecnConfigs as $ecnCfg) {
            $sideFamily = ($ecnCfg['side'] === 'COMMON') ? 'COMMON' : (($ecnCfg['side'] === 'LH') ? 'LEFT' : 'RIGHT');

            $ecnReq = EcnRequirement::create([
                'project_id' => $project->id,
                'ecn_import_batch_id' => $ecnBatch->id,
                'ecn_number' => $ecnCfg['ecn'],
                'jig_no' => $ecnCfg['jig'],
                'unit_no' => $ecnCfg['unit'],
                'part_no' => $ecnCfg['part'],
                'side' => $ecnCfg['side'],
                'side_display' => $ecnCfg['side'],
                'side_family' => $sideFamily,
                'required_qty' => $ecnCfg['req'],
                'received_qty' => $ecnCfg['rec'],
                'current_state' => $ecnCfg['state'],
            ]);

            if ($ecnCfg['rec_status']) {
                $ecnRecItem = EcnReceiptItem::create([
                    'ecn_requirement_id' => $ecnReq->id,
                    'project_id' => $project->id,
                    'ecn_number' => $ecnCfg['ecn'],
                    'side' => $ecnCfg['side'],
                    'side_display' => $ecnCfg['side'],
                    'received_quantity' => $ecnCfg['rec'],
                    'status' => $ecnCfg['rec_status'],
                    'remarks' => "ECN {$ecnCfg['state']} intake",
                    'processed_by' => $storeUser->id,
                ]);

                if ($ecnCfg['state'] === 'REWORK') {
                    EcnWorkflowRecord::create([
                        'ecn_receipt_item_id' => $ecnRecItem->id,
                        'ecn_requirement_id' => $ecnReq->id,
                        'project_id' => $project->id,
                        'ecn_number' => $ecnCfg['ecn'],
                        'department' => 'REWORK',
                        'action' => 'rework_in_progress',
                        'side' => $ecnCfg['side'],
                        'quantity' => $ecnCfg['rec'],
                        'status' => 'in_progress',
                        'processed_by' => $reworkUser->id,
                    ]);
                } elseif ($ecnCfg['state'] === 'ASSEMBLY') {
                    EcnWorkflowRecord::create([
                        'ecn_receipt_item_id' => $ecnRecItem->id,
                        'ecn_requirement_id' => $ecnReq->id,
                        'project_id' => $project->id,
                        'ecn_number' => $ecnCfg['ecn'],
                        'department' => 'ASSEMBLY',
                        'action' => 'assembly_in_progress',
                        'side' => $ecnCfg['side'],
                        'quantity' => $ecnCfg['rec'],
                        'status' => 'in_progress',
                        'processed_by' => $assemblyUser->id,
                    ]);
                }
            }
        }
    }

    /**
     * Clean existing project records to ensure exact count of 8 projects and 15,343 parts.
     */
    protected function cleanExistingProjects(): void
    {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        // Delete workflow events and records
        DB::table('ecn_workflow_events')->delete();
        DB::table('ecn_workflow_records')->delete();
        DB::table('ecn_receipt_items')->delete();
        DB::table('ecn_requirements')->delete();
        DB::table('ecn_import_batches')->delete();

        DB::table('workflow_events')->delete();
        DB::table('purchase_queue_items')->delete();
        DB::table('assembly_records')->delete();
        DB::table('paint_records')->delete();
        DB::table('rework_records')->delete();
        DB::table('qc_inspections')->delete();
        DB::table('receipt_items')->delete();
        DB::table('receipts')->delete();
        DB::table('bom_requirements')->delete();
        DB::table('bom_items')->delete();
        DB::table('bom_import_batches')->delete();
        DB::table('supplier_assignments')->delete();
        DB::table('supplier_assignment_history')->delete();
        DB::table('projects')->delete();
    }
}
