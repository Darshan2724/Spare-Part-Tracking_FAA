<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecn_workflow_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ecn_receipt_item_id')->nullable()->constrained('ecn_receipt_items')->cascadeOnDelete();
            $table->foreignId('ecn_requirement_id')->constrained('ecn_requirements')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete()->index();
            $table->string('ecn_number')->index();
            $table->enum('department', ['QC', 'REWORK', 'PAINT', 'ASSEMBLY'])->index();
            $table->string('action')->index(); // qc_received, qc_approved, qc_rejected, qc_rework, rework_completed, paint_completed, assembly_completed
            $table->string('side', 10)->index();
            $table->string('side_display', 10)->index();
            $table->integer('quantity')->default(0);
            $table->string('destination')->nullable(); // PAINT, ASSEMBLY, REWORK
            $table->integer('approved_quantity')->default(0);
            $table->integer('rejected_quantity')->default(0);
            $table->integer('rework_quantity')->default(0);
            $table->text('remarks')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('completed')->index(); // pending, in_progress, completed, reverted
            $table->timestamps();

            $table->index(['ecn_requirement_id', 'department'], 'ecn_wf_req_dept_idx');
            $table->index(['project_id', 'department'], 'ecn_wf_proj_dept_idx');
            $table->index(['ecn_receipt_item_id', 'department'], 'ecn_wf_receipt_dept_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecn_workflow_records');
    }
};
