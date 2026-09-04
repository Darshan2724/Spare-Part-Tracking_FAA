<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Update bom_items table
        Schema::table('bom_items', function (Blueprint $table) {
            $table->string('part_type', 10)->default('MFG')->after('proj_spec_yn');
            $table->index(['project_id', 'part_type'], 'bom_items_project_id_part_type_index');
        });

        // Add check constraint for part_type
        DB::statement("ALTER TABLE bom_items ADD CONSTRAINT chk_bom_items_part_type CHECK (part_type IN ('MFG', 'BOP', 'STD'))");

        // Drop the previous unique constraint and add new one scoped by part_type
        Schema::table('bom_items', function (Blueprint $table) {
            $table->dropUnique('bom_items_proj_jig_unit_part_unique');
            $table->unique(
                ['project_id', 'jig_no', 'unit_no', 'standard_part_no', 'part_type'],
                'bom_items_proj_jig_unit_part_type_unique'
            );
        });

        // 2. Update bom_import_batches table
        Schema::table('bom_import_batches', function (Blueprint $table) {
            $table->string('bom_type', 10)->default('MFG')->after('import_type');
            $table->index(['project_id', 'bom_type'], 'bom_import_batches_project_id_bom_type_index');
        });

        // Add check constraint for bom_type
        DB::statement("ALTER TABLE bom_import_batches ADD CONSTRAINT chk_bom_import_batches_bom_type CHECK (bom_type IN ('MFG', 'BOP', 'STD'))");
    }

    public function down(): void
    {
        // Revert bom_import_batches
        DB::statement("ALTER TABLE bom_import_batches DROP CONSTRAINT IF EXISTS chk_bom_import_batches_bom_type");
        Schema::table('bom_import_batches', function (Blueprint $table) {
            $table->dropIndex('bom_import_batches_project_id_bom_type_index');
            $table->dropColumn('bom_type');
        });

        // Revert bom_items
        DB::statement("ALTER TABLE bom_items DROP CONSTRAINT IF EXISTS chk_bom_items_part_type");
        Schema::table('bom_items', function (Blueprint $table) {
            $table->dropUnique('bom_items_proj_jig_unit_part_type_unique');
            $table->unique(
                ['project_id', 'jig_no', 'unit_no', 'standard_part_no'],
                'bom_items_proj_jig_unit_part_unique'
            );
            $table->dropIndex('bom_items_project_id_part_type_index');
            $table->dropColumn('part_type');
        });
    }
};
