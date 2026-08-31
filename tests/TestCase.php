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
        parent::tearDown();

        try {
            $realProjectIds = [16, 723];
            $testProjects = Project::whereNotIn('id', $realProjectIds)
                ->where(function ($q) {
                    $q->where('project_code', 'LIKE', 'TEST-%')
                      ->orWhere('project_code', 'LIKE', 'CAT-%')
                      ->orWhere('project_code', 'LIKE', 'PIT-%')
                      ->orWhere('project_code', 'LIKE', 'SIT-%')
                      ->orWhere('project_code', 'LIKE', 'SFT-%')
                      ->orWhere('project_code', 'LIKE', 'LIT-%')
                      ->orWhere('project_code', 'LIKE', 'QRT-%')
                      ->orWhere('project_code', 'LIKE', 'PRT-%')
                      ->orWhere('project_code', 'LIKE', 'PROJ-FULL-%')
                      ->orWhere('project_code', 'LIKE', 'FA-279-%')
                      ->orWhere('name', 'LIKE', 'TEST-%')
                      ->orWhere('name', 'LIKE', 'Flow Project%')
                      ->orWhere('name', 'LIKE', '%Test%');
                })->get();

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

                DB::table('receipts')->whereIn('project_id', $testProjIds)->delete();

                if (!empty($ecnReqIds)) {
                    DB::table('ecn_workflow_records')->whereIn('ecn_requirement_id', $ecnReqIds)->delete();
                    DB::table('ecn_receipt_items')->whereIn('ecn_requirement_id', $ecnReqIds)->delete();
                    DB::table('ecn_requirements')->whereIn('id', $ecnReqIds)->delete();
                }

                DB::table('projects')->whereIn('id', $testProjIds)->delete();
            }
        } catch (\Throwable $e) {
            // Ignore DB errors during teardown
        }
    }
}
