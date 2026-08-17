<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bom_items', function (Blueprint $table) {
            $table->string('jig_no')->nullable()->after('project_id')->index();
            $table->string('unit_no')->nullable()->after('jig_no')->index();
            $table->string('item_no')->nullable()->change();
            $table->string('size')->nullable()->change();
            $table->string('proj_spec_yn', 5)->nullable()->default('Y')->change();

            $table->dropUnique('bom_items_project_id_standard_part_no_unique');
            $table->unique(['project_id', 'jig_no', 'unit_no', 'standard_part_no'], 'bom_items_proj_jig_unit_part_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bom_items', function (Blueprint $table) {
            $table->dropUnique('bom_items_proj_jig_unit_part_unique');
            $table->unique(['project_id', 'standard_part_no'], 'bom_items_project_id_standard_part_no_unique');
            $table->dropColumn(['jig_no', 'unit_no']);
        });
    }
};
