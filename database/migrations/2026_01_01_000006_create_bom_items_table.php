<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('item_no')->nullable(); // Original BOM item number e.g. "1", "2"
            $table->string('standard_part_no')->index(); // PRIMARY SEARCH FIELD e.g. 62800-ST7-01-11-R
            $table->string('size')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('supplier_name_raw')->nullable(); // Original string from Excel
            $table->text('remarks')->nullable();
            $table->string('proj_spec_yn', 5)->default('Y');
            $table->foreignId('import_batch_id')->nullable()->constrained('bom_import_batches')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'standard_part_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_items');
    }
};
