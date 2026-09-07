<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Receipts indexes
        Schema::table('receipts', function (Blueprint $table) {
            $table->index(['project_id', 'created_at'], 'receipts_project_created_idx');
        });

        // 2. Receipt Items indexes
        Schema::table('receipt_items', function (Blueprint $table) {
            $table->index('receipt_id', 'receipt_items_receipt_id_idx');
            $table->index('updated_at', 'receipt_items_updated_at_idx');
        });

        // 3. BOM Items composite index for fast part_type lookups
        Schema::table('bom_items', function (Blueprint $table) {
            $table->index(['project_id', 'part_type', 'standard_part_no'], 'bom_items_proj_type_part_idx');
        });

        // 4. QC Inspections inspector index
        Schema::table('qc_inspections', function (Blueprint $table) {
            $table->index('inspected_by', 'qc_inspections_inspected_by_idx');
        });

        // 5. Workflow Events created_at index for date-range grouping
        Schema::table('workflow_events', function (Blueprint $table) {
            $table->index('created_at', 'workflow_events_created_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workflow_events', function (Blueprint $table) {
            $table->dropIndex('workflow_events_created_at_idx');
        });

        Schema::table('qc_inspections', function (Blueprint $table) {
            $table->dropIndex('qc_inspections_inspected_by_idx');
        });

        Schema::table('bom_items', function (Blueprint $table) {
            $table->dropIndex('bom_items_proj_type_part_idx');
        });

        Schema::table('receipt_items', function (Blueprint $table) {
            $table->dropIndex('receipt_items_receipt_id_idx');
            $table->dropIndex('receipt_items_updated_at_idx');
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropIndex('receipts_project_created_idx');
        });
    }
};
