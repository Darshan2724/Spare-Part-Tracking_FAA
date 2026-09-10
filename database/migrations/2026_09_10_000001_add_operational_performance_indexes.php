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
        // 1. Rework records foreign key and aggregation indexes
        Schema::table('rework_records', function (Blueprint $table) {
            $table->index('bom_item_id', 'idx_rework_records_bom_item_id');
            $table->index(['bom_item_id', 'side', 'status'], 'idx_rework_bom_side_status');
        });

        // 2. Paint records foreign key and aggregation indexes
        Schema::table('paint_records', function (Blueprint $table) {
            $table->index('bom_item_id', 'idx_paint_records_bom_item_id');
            $table->index(['bom_item_id', 'side', 'status'], 'idx_paint_bom_side_status');
        });

        // 3. Assembly records foreign key and aggregation indexes
        Schema::table('assembly_records', function (Blueprint $table) {
            $table->index('bom_item_id', 'idx_assembly_records_bom_item_id');
            $table->index(['bom_item_id', 'side', 'status'], 'idx_assembly_bom_side_status');
        });

        // 4. QC Inspections fast aggregation index
        Schema::table('qc_inspections', function (Blueprint $table) {
            $table->index(['bom_item_id', 'side', 'result'], 'idx_qc_bom_side_result');
        });

        // 5. Receipt items fast valid-status aggregation index
        Schema::table('receipt_items', function (Blueprint $table) {
            $table->index(['bom_item_id', 'status'], 'idx_receipt_items_bom_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receipt_items', function (Blueprint $table) {
            $table->dropIndex('idx_receipt_items_bom_status');
        });

        Schema::table('qc_inspections', function (Blueprint $table) {
            $table->dropIndex('idx_qc_bom_side_result');
        });

        Schema::table('assembly_records', function (Blueprint $table) {
            $table->dropIndex('idx_assembly_records_bom_item_id');
            $table->dropIndex('idx_assembly_bom_side_status');
        });

        Schema::table('paint_records', function (Blueprint $table) {
            $table->dropIndex('idx_paint_records_bom_item_id');
            $table->dropIndex('idx_paint_bom_side_status');
        });

        Schema::table('rework_records', function (Blueprint $table) {
            $table->dropIndex('idx_rework_records_bom_item_id');
            $table->dropIndex('idx_rework_bom_side_status');
        });
    }
};
