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
        // 1. Receipt Items: composite index for valid receipts and status filtering
        Schema::table('receipt_items', function (Blueprint $table) {
            $table->index(['bom_item_id', 'side', 'status'], 'idx_receipt_items_item_side_status');
        });

        // 2. QC Inspections: composite index on bom_item_id, side, destination
        Schema::table('qc_inspections', function (Blueprint $table) {
            $table->index(['bom_item_id', 'side', 'destination'], 'idx_qc_insp_item_side_dest');
        });

        // 3. Rework Records: composite index on bom_item_id, side, status
        Schema::table('rework_records', function (Blueprint $table) {
            $table->index(['bom_item_id', 'side', 'status'], 'idx_rework_item_side_status');
        });

        // 4. Paint Records: composite index on bom_item_id, side, status
        Schema::table('paint_records', function (Blueprint $table) {
            $table->index(['bom_item_id', 'side', 'status'], 'idx_paint_item_side_status');
        });

        // 5. Assembly Records: composite index on bom_item_id, side, status
        Schema::table('assembly_records', function (Blueprint $table) {
            $table->index(['bom_item_id', 'side', 'status'], 'idx_assembly_item_side_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assembly_records', function (Blueprint $table) {
            $table->dropIndex('idx_assembly_item_side_status');
        });

        Schema::table('paint_records', function (Blueprint $table) {
            $table->dropIndex('idx_paint_item_side_status');
        });

        Schema::table('rework_records', function (Blueprint $table) {
            $table->dropIndex('idx_rework_item_side_status');
        });

        Schema::table('qc_inspections', function (Blueprint $table) {
            $table->dropIndex('idx_qc_insp_item_side_dest');
        });

        Schema::table('receipt_items', function (Blueprint $table) {
            $table->dropIndex('idx_receipt_items_item_side_status');
        });
    }
};
