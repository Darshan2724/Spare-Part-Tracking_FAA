<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Project;
use App\Models\Supplier;

abstract class TestCase extends BaseTestCase
{
    /**
     * Clean up all test projects and test suppliers created during feature tests to ensure zero test artifacts remain in database.
     */
    protected function tearDown(): void
    {
        try {
            // 1. Clean test projects (Preserve ONLY FA-273 and FA-279)
            $protectedCodes = ['FA-273', 'FA-279'];
            $protectedIds = [391, 392];

            $testProjIds = DB::table('projects')
                ->whereNotIn('id', $protectedIds)
                ->whereNotIn('project_code', $protectedCodes)
                ->pluck('id')
                ->toArray();

            if (!empty($testProjIds)) {
                $tablesWithProjectId = [
                    'supplier_assignment_history',
                    'supplier_assignments',
                    'assembly_records',
                    'paint_records',
                    'rework_records',
                    'qc_inspections',
                    'receipt_items',
                    'receipts',
                    'bom_requirements',
                    'purchase_queue_items',
                    'workflow_events',
                    'ecn_workflow_records',
                    'ecn_receipt_items',
                    'ecn_requirements',
                    'ecn_workflow_events',
                    'ecn_assignments',
                    'ecn_records',
                    'bom_items',
                ];

                foreach ($tablesWithProjectId as $tbl) {
                    if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, 'project_id')) {
                        DB::table($tbl)->whereIn('project_id', $testProjIds)->delete();
                    }
                }

                DB::table('projects')->whereIn('id', $testProjIds)->delete();
            }

            // 2. Clean test suppliers (Preserve genuine FA registered suppliers IDs 22 to 82)
            $testSuppliers = DB::table('suppliers')
                ->where(function ($q) {
                    $q->where('id', '<', 22)
                      ->orWhere('id', '>', 82)
                      ->orWhere('is_test_data', true)
                      ->orWhere('code', 'LIKE', 'TEST_%')
                      ->orWhere('code', 'LIKE', 'SUPP-T-%')
                      ->orWhere('code', 'LIKE', 'SUP-%')
                      ->orWhere('name', 'LIKE', 'Apex Precision Works%')
                      ->orWhere('name', 'LIKE', 'Test Supplier%');
                })
                ->whereNull('supplier_import_id')
                ->get();

            $testSuppIds = $testSuppliers->pluck('id')->toArray();
            if (!empty($testSuppIds)) {
                DB::table('supplier_phones')->whereIn('supplier_id', $testSuppIds)->delete();
                DB::table('supplier_assignment_history')
                    ->whereIn('new_supplier_id', $testSuppIds)
                    ->orWhereIn('previous_supplier_id', $testSuppIds)
                    ->delete();
                DB::table('supplier_assignments')->whereIn('supplier_id', $testSuppIds)->delete();
                DB::table('suppliers')->whereIn('id', $testSuppIds)->delete();
            }
        } catch (\Throwable $e) {
            // Log teardown error if any during test debugging
            error_log('Test teardown notice: ' . $e->getMessage());
        }

        parent::tearDown();
    }
}
