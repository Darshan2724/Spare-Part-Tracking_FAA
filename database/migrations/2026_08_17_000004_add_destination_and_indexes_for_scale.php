<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add destination column to qc_inspections (no default, must be explicitly set to PAINT or ASSEMBLY on approval)
        Schema::table('qc_inspections', function (Blueprint $table) {
            if (!Schema::hasColumn('qc_inspections', 'destination')) {
                $table->string('destination', 20)->nullable()->after('result');
            }
        });

        // 2. Update assembly_records to support direct QC routing without paint
        Schema::table('assembly_records', function (Blueprint $table) {
            // Drop foreign key and make paint_record_id nullable if exists
            if (Schema::hasColumn('assembly_records', 'paint_record_id')) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement("ALTER TABLE assembly_records ALTER COLUMN paint_record_id DROP NOT NULL;");
                }
            }

            if (!Schema::hasColumn('assembly_records', 'qc_inspection_id')) {
                $table->foreignId('qc_inspection_id')->nullable()->after('paint_record_id')->constrained('qc_inspections')->nullOnDelete();
            }
        });

        // 3. Add High-Performance Composite Indexes for 5,000-6,000 parts/day scale
        Schema::table('receipt_items', function (Blueprint $table) {
            $table->index(['bom_item_id', 'side', 'status'], 'idx_receipt_items_lookup');
        });

        Schema::table('qc_inspections', function (Blueprint $table) {
            $table->index(['receipt_item_id', 'side', 'result'], 'idx_qc_inspections_lookup');
            $table->index(['destination', 'result'], 'idx_qc_inspections_dest');
        });

        Schema::table('paint_records', function (Blueprint $table) {
            $table->index(['qc_inspection_id', 'side', 'status'], 'idx_paint_records_lookup');
        });

        Schema::table('assembly_records', function (Blueprint $table) {
            $table->index(['bom_item_id', 'side', 'status'], 'idx_assembly_records_lookup');
        });

        Schema::table('rework_records', function (Blueprint $table) {
            $table->index(['bom_item_id', 'side', 'status'], 'idx_rework_records_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('rework_records', function (Blueprint $table) {
            $table->dropIndex('idx_rework_records_lookup');
        });

        Schema::table('assembly_records', function (Blueprint $table) {
            $table->dropIndex('idx_assembly_records_lookup');
            $table->dropForeign(['qc_inspection_id']);
            $table->dropColumn('qc_inspection_id');
        });

        Schema::table('paint_records', function (Blueprint $table) {
            $table->dropIndex('idx_paint_records_lookup');
        });

        Schema::table('qc_inspections', function (Blueprint $table) {
            $table->dropIndex('idx_qc_inspections_dest');
            $table->dropIndex('idx_qc_inspections_lookup');
            $table->dropColumn('destination');
        });

        Schema::table('receipt_items', function (Blueprint $table) {
            $table->dropIndex('idx_receipt_items_lookup');
        });
    }
};
