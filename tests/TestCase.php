<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use App\Models\Project;

abstract class TestCase extends BaseTestCase
{
    /**
     * Clean up test projects created during feature tests to ensure zero test artifacts remain in database.
     */
    protected function tearDown(): void
    {
        try {
            $protectedCodes = ['FA-273', 'FA-279'];
            $testProjects = Project::whereNotIn('project_code', $protectedCodes)
                ->where(function ($q) {
                    $q->where('project_code', 'LIKE', 'TEST-%')
                      ->orWhere('project_code', 'LIKE', 'SP-1-%')
                      ->orWhere('project_code', 'LIKE', 'AP-1-%')
                      ->orWhere('project_code', 'LIKE', 'CP-2-%')
                      ->orWhere('project_code', 'LIKE', 'FA-NAV-%')
                      ->orWhere('name', 'LIKE', '%Test%')
                      ->orWhere('is_test_data', true);
                })
                ->get();
            $testProjIds = $testProjects->pluck('id')->toArray();

            if (!empty($testProjIds)) {
                $bomItemIds = DB::table('bom_items')->whereIn('project_id', $testProjIds)->pluck('id')->toArray();
                $ecnReqIds = DB::table('ecn_requirements')->whereIn('project_id', $testProjIds)->pluck('id')->toArray();

                if (!empty($bomItemIds)) {
                    DB::table('assembly_records')->whereIn('bom_item_id', $bomItemIds)->delete();
                    DB::table('paint_records')->whereIn('bom_item_id', $bomItemIds)->delete();
                    DB::table('rework_records')->whereIn('bom_item_id', $bomItemIds)->delete();
                    DB::table('qc_inspections')->whereIn('bom_item_id', $bomItemIds)->delete();
                    DB::table('receipt_items')->whereIn('bom_item_id', $bomItemIds)->delete();
                    DB::table('bom_requirements')->whereIn('bom_item_id', $bomItemIds)->delete();
                    DB::table('bom_items')->whereIn('id', $bomItemIds)->delete();
                }

                DB::table('purchase_queue_items')->whereIn('project_id', $testProjIds)->delete();
                DB::table('workflow_events')->whereIn('project_id', $testProjIds)->delete();
                DB::table('ecn_workflow_events')->whereIn('project_id', $testProjIds)->delete();
                DB::table('receipts')->whereIn('project_id', $testProjIds)->delete();

                if (!empty($ecnReqIds)) {
                    DB::table('ecn_workflow_records')->whereIn('ecn_requirement_id', $ecnReqIds)->delete();
                    DB::table('ecn_receipt_items')->whereIn('ecn_requirement_id', $ecnReqIds)->delete();
                    DB::table('ecn_requirements')->whereIn('id', $ecnReqIds)->delete();
                }

                DB::table('projects')->whereIn('id', $testProjIds)->delete();
            }

            DB::table('suppliers')
                ->where('code', 'LIKE', 'TEST_%')
                ->orWhere('code', 'LIKE', 'SUPP-T-%')
                ->orWhere('code', 'LIKE', 'TSC-%')
                ->orWhere('code', 'LIKE', 'TSF-%')
                ->orWhere('code', 'LIKE', 'TSQ-%')
                ->orWhere('code', 'LIKE', 'TSP-%')
                ->orWhere('code', 'LIKE', 'TSC2-%')
                ->orWhere('is_test_data', true)
                ->delete();
        } catch (\Throwable $e) {
            // Ignore DB errors during teardown
        }

        parent::tearDown();
    }
}
