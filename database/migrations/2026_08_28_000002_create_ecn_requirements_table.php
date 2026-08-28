<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecn_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('ecn_import_batch_id')->nullable()->constrained('ecn_import_batches')->nullOnDelete();
            $table->string('ecn_number')->index();
            $table->string('jig_no')->index();
            $table->string('unit_no')->index();
            $table->string('part_no')->index();
            $table->string('side', 10)->index(); // LA, RA, AL, AR, L, R
            $table->string('side_display', 10)->index(); // LH, RH
            $table->enum('side_family', ['LEFT', 'RIGHT'])->index();
            $table->integer('required_qty')->default(0);
            $table->integer('received_qty')->default(0);
            $table->enum('current_state', [
                'PENDING',
                'STORE',
                'QC',
                'REWORK',
                'PAINT',
                'ASSEMBLY',
                'ASSEMBLY_COMPLETED'
            ])->default('PENDING')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['project_id', 'ecn_number', 'jig_no', 'unit_no', 'part_no', 'side'],
                'ecn_req_proj_ecn_jig_unit_part_side_unique'
            );
            $table->index(['project_id', 'jig_no', 'unit_no'], 'ecn_req_proj_jig_unit_idx');
            $table->index(['project_id', 'ecn_number'], 'ecn_req_proj_ecn_idx');
            $table->index(['project_id', 'current_state'], 'ecn_req_proj_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecn_requirements');
    }
};
